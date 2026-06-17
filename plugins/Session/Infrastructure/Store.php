<?php

declare(strict_types=1);

namespace Plugins\Session\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;

/**
 * Store — the SessionPort adapter (focused GDA rewrite of the 0.3 Session\Store).
 *
 * Holds the loaded attribute bag for one request, persists it through a native
 * \SessionHandlerInterface, and implements the genuinely useful Laravel-style
 * surface (flash data, CSRF token, fixation defence) WITHOUT pulling in Symfony
 * sessions or PHP's global $_SESSION machinery.
 *
 * Flash bookkeeping uses two reserved keys inside the bag:
 *   _flash.new — keys flashed during THIS request (live next request)
 *   _flash.old — keys flashed last request (live now, removed on next start)
 */
final class Store implements SessionPort
{
    private const TOKEN_KEY = '_token';

    /** @var array<string, mixed> */
    private array $attributes = [];

    private string $id;
    private bool $started = false;
    /** True once a public mutator ran — gates lazy persistence. */
    private bool $dirty = false;
    /** True when start() loaded a pre-existing session from the handler. */
    private bool $existed = false;

    public function __construct(
        private readonly string $name,
        private readonly \SessionHandlerInterface $handler,
        private readonly string $serialization = 'json',
    ) {
        $this->id = $this->generateId();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function start(?string $id = null): void
    {
        $this->id = $this->isValidId($id) ? (string) $id : $this->generateId();

        $raw = $this->handler->read($this->id);
        $this->existed = $raw !== '' && $raw !== false;
        $this->attributes = $this->existed ? $this->unserialize($raw) : [];

        // Seed a CSRF token on a brand-new session WITHOUT marking it dirty —
        // an otherwise-unused session must not be persisted just for the token.
        if (!$this->has(self::TOKEN_KEY)) {
            $this->setRaw(self::TOKEN_KEY, bin2hex(random_bytes(20)));
        }

        $this->ageFlashData();
        $this->dirty = false; // loading + token seed + flash aging are not "use"
        $this->started = true;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
        $this->dirty = true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function push(string $key, mixed $value): void
    {
        $array   = $this->get($key, []);
        $array   = is_array($array) ? $array : [$array];
        $array[] = $value;
        $this->put($key, $array);
    }

    public function increment(string $key, int $by = 1): int
    {
        $value = (int) $this->get($key, 0) + $by;
        $this->put($key, $value);
        return $value;
    }

    public function forget(string $key): void
    {
        unset($this->attributes[$key]);
        $this->dirty = true;
    }

    public function flush(): void
    {
        $token = $this->get(self::TOKEN_KEY);
        $this->attributes = [];
        if (is_string($token)) {
            $this->attributes[self::TOKEN_KEY] = $token;
        }
        $this->dirty = true;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->attributes;
    }

    // ── Flash data ────────────────────────────────────────────────────────────

    public function flash(string $key, mixed $value): void
    {
        $this->put($key, $value);
        $new = $this->get('_flash.new', []);
        $new = is_array($new) ? $new : [];
        if (!in_array($key, $new, true)) {
            $new[] = $key;
        }
        $this->put('_flash.new', $new);

        // A freshly flashed key must not be aged out by this request's old set.
        $old = array_values(array_diff($this->arr('_flash.old'), [$key]));
        $this->put('_flash.old', $old);
    }

    public function reflash(): void
    {
        $merged = array_values(array_unique(array_merge($this->arr('_flash.new'), $this->arr('_flash.old'))));
        $this->put('_flash.new', $merged);
        $this->put('_flash.old', []);
    }

    private function ageFlashData(): void
    {
        foreach ($this->arr('_flash.old') as $key) {
            unset($this->attributes[$key]);
        }
        $this->setRaw('_flash.old', $this->arr('_flash.new'));
        $this->setRaw('_flash.new', []);
    }

    // ── CSRF token ────────────────────────────────────────────────────────────

    public function token(): string
    {
        $token = $this->get(self::TOKEN_KEY);
        return is_string($token) ? $token : '';
    }

    public function regenerateToken(): void
    {
        $this->put(self::TOKEN_KEY, bin2hex(random_bytes(20)));
    }

    // ── Identity / fixation defence ────────────────────────────────────────────

    public function regenerate(): void
    {
        $this->handler->destroy($this->id);
        $this->id = $this->generateId();
        $this->regenerateToken();
        $this->dirty = true;
    }

    public function invalidate(): void
    {
        $this->handler->destroy($this->id);
        $this->attributes = [];
        $this->id = $this->generateId();
        $this->regenerateToken();
        $this->dirty = true;
    }

    public function shouldPersist(): bool
    {
        return $this->existed || $this->dirty;
    }

    public function save(): void
    {
        $this->handler->write($this->id, $this->serialize($this->attributes));
        $this->started = false;
    }

    // ── Internals ───────────────────────────────────────────────────────────────

    /** Write an attribute WITHOUT marking the session dirty (lifecycle only). */
    private function setRaw(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /** @return list<string> */
    private function arr(string $key): array
    {
        $value = $this->get($key, []);
        return is_array($value) ? array_values($value) : [];
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(20)); // 40 hex chars
    }

    private function isValidId(?string $id): bool
    {
        return $id !== null && preg_match('/^[a-f0-9]{40}$/', $id) === 1;
    }

    /** @param array<string, mixed> $data */
    private function serialize(array $data): string
    {
        return $this->serialization === 'php'
            ? serialize($data)
            : (json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    }

    /** @return array<string, mixed> */
    private function unserialize(string $raw): array
    {
        if ($this->serialization === 'php') {
            $data = @unserialize($raw, ['allowed_classes' => false]);
            return is_array($data) ? $data : [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
