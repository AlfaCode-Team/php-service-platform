<?php

declare(strict_types=1);

namespace Project\Support\Entity;

use ArrayAccess;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use LogicException;
use Project\Support\Casting\DataCaster;
use Stringable;

/**
 * Enterprise GDA-safe Entity base.
 *
 * The refactored core of the legacy HKM Active-Record `Entity`, hardened to be
 * the base class for EVERY domain entity — while remaining strictly inside the
 * Gated Demand Architecture rules:
 *
 *   - NO persistence (save/delete/getRepo_/meta) — that is the Repository's job.
 *   - NO I/O, NO container, NO globals (app()/kernel()/config()).
 *   - Imports ONLY the sibling casting utility.
 *
 * Feature set
 *   • Attribute bag with bidirectional type casting ($casts + DataCaster)
 *   • Mass-assignment protection ($fillable / $guarded) — security by default
 *   • Visibility control ($hidden / $visible, runtime makeHidden/makeVisible)
 *   • Computed attributes ($appends) surfaced through accessors
 *   • Typed, null-safe getters (getString/getInt/getBool/getFloat/getArray/getDate)
 *   • Rich change tracking (isDirty/isClean/wasChanged/getOriginal/getDirty)
 *   • Domain-event buffering (recordEvent/releaseEvents) for the Service pattern
 *   • Immutability sealing (seal()) for read-only hydrated snapshots
 *   • Safe debug redaction (__debugInfo) so secrets never leak to logs/dumps
 *   • Identity comparison (is/equals), replication (replicate), Stringable
 *   • Hydrator seam: reconstitute() / toRawArray()
 *
 * @implements ArrayAccess<string, mixed>
 */
abstract class Entity implements JsonSerializable, ArrayAccess, Stringable
{
    /** Primary key field name. */
    protected string $primaryKey = 'id';

    /** Raw stored attributes (DataSource shape). @var array<string, mixed> */
    protected array $attributes = [];

    /** Snapshot for change tracking. @var array<string, mixed> */
    protected array $original = [];

    /**
     * Field => cast type (DataCaster grammar, e.g. 'int', '?json[array]').
     * @var array<string, string>
     */
    protected array $casts = [];

    /** Custom cast handlers [type => CastInterface class]. @var array<string, class-string> */
    protected array $customCasters = [];

    /**
     * Mass-assignment whitelist. Empty = fall back to $guarded.
     * @var list<string>
     */
    protected array $fillable = [];

    /**
     * Mass-assignment blacklist. ['*'] (default) = nothing is mass-assignable
     * unless explicitly listed in $fillable — secure by default.
     * @var list<string>
     */
    protected array $guarded = ['*'];

    /** Fields hidden from array/JSON output. @var list<string> */
    protected array $hidden = [];

    /** If non-empty, ONLY these fields appear in array/JSON output. @var list<string> */
    protected array $visible = [];

    /** Computed accessor names appended to array/JSON output. @var list<string> */
    protected array $appends = [];

    /** Fields serialized as formatted dates. @var list<string> */
    protected array $dates = [];

    /** Serialization date format. */
    protected string $dateFormat = 'Y-m-d H:i:s';

    /** Buffered domain events, flushed by releaseEvents(). @var list<object> */
    private array $domainEvents = [];

    /** When sealed, the attribute bag is immutable (read-only snapshot). */
    private bool $sealed = false;

    private ?DataCaster $dataCaster = null;

    /** Method-existence memoization, keyed by class::method (immutable facts). @var array<string, bool> */
    private static array $methodCache = [];

    /**
     * @param array<string, mixed>|null $attributes Raw DataSource attributes (bypasses guards — hydration)
     */
    final public function __construct(?array $attributes = null)
    {
        if ($attributes !== null) {
            $this->attributes = $attributes;
            $this->syncOriginal();
        }
    }

    // ── Reconstitution / replication / extraction (the Hydrator seam) ────────

    /**
     * Rebuild an entity from a raw DataSource row. Records NO events — this is
     * hydration, not creation. Called by DataConverter by name.
     *
     * @param array<string, mixed> $row
     */
    public static function reconstitute(array $row): static
    {
        return new static($row);
    }

    /** A blank instance of the concrete entity. */
    public static function make(): static
    {
        return new static();
    }

    /**
     * A copy WITHOUT the primary key (and optionally other fields) — for
     * cloning an aggregate into a brand-new record. Never sealed, never dirty-tracked yet.
     *
     * @param list<string> $except additional fields to drop
     */
    public function replicate(array $except = []): static
    {
        $drop = array_merge([$this->primaryKey], $except);
        $attributes = array_diff_key($this->attributes, array_flip($drop));

        return new static($attributes);
    }

    /**
     * Raw attribute array for persistence (optionally only changed fields).
     * This is what a Repository hands to DatabasePort::upsert()/update().
     *
     * @return array<string, mixed>
     */
    public function toRawArray(bool $onlyChanged = false): array
    {
        return $onlyChanged ? $this->getChanges() : $this->attributes;
    }

    // ── Mass assignment (secure by default) ──────────────────────────────────

    /**
     * Mass-assign, respecting $fillable / $guarded. Unfillable keys are
     * silently skipped — prevents over-posting / mass-assignment injection.
     *
     * @param array<string, mixed> $data
     */
    public function fill(array $data): static
    {
        foreach ($data as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /**
     * Mass-assign bypassing guards — use ONLY for trusted internal data.
     *
     * @param array<string, mixed> $data
     */
    public function forceFill(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function isFillable(string $key): bool
    {
        if (in_array($key, $this->fillable, true)) {
            return true;
        }

        // Anything explicitly guarded (or the wildcard) is rejected.
        if (in_array($key, $this->guarded, true) || in_array('*', $this->guarded, true)) {
            return false;
        }

        // No fillable whitelist and not guarded => allowed.
        return $this->fillable === [];
    }

    // ── Attribute access (casting + accessor/mutator hooks) ──────────────────

    /**
     * Read an attribute: applies a `get`-direction cast and any
     * get{Studly}Attribute() accessor the subclass defines.
     */
    public function getAttribute(string $key): mixed
    {
        $value = $this->attributes[$key] ?? null;

        if ($value !== null && isset($this->casts[$key])) {
            $value = $this->caster()->castAs($value, $key, 'get');
        }

        $accessor = 'get' . $this->studly($key) . 'Attribute';
        if ($this->methodExists($accessor)) {
            return $this->{$accessor}($value);
        }

        return $value;
    }

    /**
     * Write an attribute: applies any set{Studly}Attribute() mutator, then a
     * `set`-direction cast so the stored value is in DataSource shape.
     */
    public function setAttribute(string $key, mixed $value): static
    {
        $this->assertNotSealed();

        $mutator = 'set' . $this->studly($key) . 'Attribute';
        if ($this->methodExists($mutator)) {
            $value = $this->{$mutator}($value);
        }

        if ($value !== null && isset($this->casts[$key])) {
            $value = $this->caster()->castAs($value, $key, 'set');
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /** Raw (uncast) stored value. */
    public function getRawAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->getAttribute($key);
        }

        return $out;
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->toArray(), array_flip($keys));
    }

    // ── Typed, null-safe accessors ───────────────────────────────────────────

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->getAttribute($key);

        return $value === null ? $default : (string) $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->getAttribute($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->getAttribute($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->getAttribute($key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<mixed> $default
     * @return array<mixed>
     */
    public function getArray(string $key, array $default = []): array
    {
        $value = $this->getAttribute($key);
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : $default;
        }

        return $default;
    }

    public function getDate(string $key): ?DateTimeImmutable
    {
        $value = $this->getAttribute($key);
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value)) {
            return (new DateTimeImmutable())->setTimestamp($value);
        }
        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    private function caster(): DataCaster
    {
        return $this->dataCaster ??= new DataCaster(
            castHandlers: $this->customCasters ?: null,
            types: $this->casts,
            strict: false,
        );
    }

    // ── JsonSerializable ─────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->jsonSerialize(), $flags | JSON_THROW_ON_ERROR);
    }

    // ── ArrayAccess (delegates to the casting accessor surface) ──────────────

    public function offsetExists(mixed $offset): bool
    {
        return $this->getAttribute((string) $offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new LogicException('Cannot push onto an entity without a key.');
        }
        $this->setAttribute((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->assertNotSealed();
        unset($this->attributes[(string) $offset]);
    }

    // ── Magic surface (bag-only — NO database fallback) ──────────────────────

    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->getAttribute($name) !== null;
    }

    public function __unset(string $name): void
    {
        $this->assertNotSealed();
        unset($this->attributes[$name]);
    }

    /**
     * Redacts hidden fields in var_dump()/debug output so secrets never leak.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $out = $this->attributes;
        foreach ($this->hidden as $key) {
            if (array_key_exists($key, $out)) {
                $out[$key] = '********';
            }
        }

        return $out;
    }

    public function __clone(): void
    {
        // A clone is a fresh, unsaved aggregate: drop tracking + buffered events.
        $this->original = [];
        $this->domainEvents = [];
        $this->sealed = false;
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    // ── Serialization (visibility + appends + dates) ─────────────────────────

    /**
     * Cast, visibility-filtered representation for API responses / DTOs.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $onlyChanged = false): array
    {
        $source = $onlyChanged ? $this->getChanges() : $this->attributes;

        $data = [];
        foreach (array_keys($source) as $key) {
            if (! $this->isVisible($key)) {
                continue;
            }
            $data[$key] = $this->serializeValue($key, $this->getAttribute($key));
        }

        // Computed/appended accessors (always subject to visibility).
        foreach ($this->appends as $key) {
            if ($this->isVisible($key)) {
                $data[$key] = $this->getAttribute($key);
            }
        }

        return $data;
    }

    private function isVisible(string $key): bool
    {
        if (in_array($key, $this->hidden, true)) {
            return false;
        }

        return $this->visible === [] || in_array($key, $this->visible, true);
    }

    private function serializeValue(string $key, mixed $value): mixed
    {
        if (in_array($key, $this->dates, true) && $value !== null) {
            return $this->getDate($key)?->format($this->dateFormat);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($this->dateFormat);
        }

        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }

        return $value;
    }

    // ── Visibility control (runtime) ─────────────────────────────────────────

    /** @param string|list<string> $keys */
    public function makeHidden(string|array $keys): static
    {
        $this->hidden = array_values(array_unique(array_merge($this->hidden, (array) $keys)));

        return $this;
    }

    /** @param string|list<string> $keys */
    public function makeVisible(string|array $keys): static
    {
        $keys = (array) $keys;
        $this->hidden = array_values(array_diff($this->hidden, $keys));
        if ($this->visible !== []) {
            $this->visible = array_values(array_unique(array_merge($this->visible, $keys)));
        }

        return $this;
    }

    // ── Change tracking ──────────────────────────────────────────────────────

    public function syncOriginal(): static
    {
        $this->original = $this->attributes;

        return $this;
    }

    public function isDirty(string ...$keys): bool
    {
        if ($keys === []) {
            return $this->attributes !== $this->original;
        }
        foreach ($keys as $key) {
            if (($this->attributes[$key] ?? null) !== ($this->original[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function isClean(string ...$keys): bool
    {
        return ! $this->isDirty(...$keys);
    }

    /** Did the given field(s) change since the last sync? (alias of isDirty for readability) */
    public function wasChanged(string ...$keys): bool
    {
        return $this->isDirty(...$keys);
    }

    /** @return array<string, mixed> */
    public function getChanges(): array
    {
        $changes = [];
        foreach ($this->attributes as $key => $value) {
            if (($this->original[$key] ?? null) !== $value) {
                $changes[$key] = $value;
            }
        }

        return $changes;
    }

    /** Alias of getChanges() for the common "dirty attributes" phrasing. @return array<string, mixed> */
    public function getDirty(): array
    {
        return $this->getChanges();
    }

    public function getOriginal(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->original;
        }

        return $this->original[$key] ?? $default;
    }

    // ── Identity ─────────────────────────────────────────────────────────────

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    public function exists(): bool
    {
        return ! in_array($this->getKey(), [null, '', 0, '0'], true);
    }

    /** True when $other is the same concrete class with the same non-empty key. */
    public function is(?Entity $other): bool
    {
        return $other !== null
            && $other::class === static::class
            && $this->exists()
            && $this->getKey() === $other->getKey();
    }

    public function isNot(?Entity $other): bool
    {
        return ! $this->is($other);
    }

    // ── Domain events (collected during state changes, flushed by the Service) ─

    protected function recordEvent(object $event): void
    {
        $this->domainEvents[] = $event;
    }

    public function hasEvents(): bool
    {
        return $this->domainEvents !== [];
    }

    /**
     * Returns AND clears the buffered domain events.
     *
     * @return list<object>
     */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    // ── Immutability sealing ─────────────────────────────────────────────────

    /**
     * Lock the entity: any further mutation throws. Use for read-only snapshots
     * (e.g. cached projections) where accidental writes must be impossible.
     */
    public function seal(): static
    {
        $this->sealed = true;

        return $this;
    }

    public function isSealed(): bool
    {
        return $this->sealed;
    }

    private function assertNotSealed(): void
    {
        if ($this->sealed) {
            throw new LogicException(static::class . ' is sealed and cannot be mutated.');
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    protected function methodExists(string $method): bool
    {
        return self::$methodCache[static::class . '::' . $method] ??= method_exists($this, $method);
    }

    protected function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
