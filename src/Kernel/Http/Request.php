<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Request — HTTP request value object backed by Symfony HttpFoundation.
 *
 * Although it extends the (mutable) Symfony Request to reuse its battle-tested
 * parsing, this class is treated as IMMUTABLE by the kernel: every mutator
 * (withHeader / withAttribute / withIdentity / withContainer / merge / replace)
 * returns a NEW instance and never touches the original. `__clone()` deep-clones
 * the underlying parameter bags so clones are fully isolated — which is what
 * makes the request safe to pass across pipeline stages and across coroutines
 * under OpenSwoole.
 *
 * The accessor API (method(), path(), input(), header(), attribute(), …) mirrors
 * the GDA kernel contract; the rich helpers mirror the dev/0.3 layer.
 */
final class Request extends SymfonyRequest
{
    /** Decoded JSON body cache. */
    private ?InputBag $json = null;

    private ?Identity $identity = null;

    private ?ModuleContainer $container = null;

    /**
     * Deep-clone the parameter bags so cloned requests are fully independent.
     * Without this, Symfony's shallow clone would share the bag objects and a
     * "with*" mutation would leak back into the original request.
     */
    public function __clone(): void
    {
        // For JSON requests createFromBase() aliases $request to the $json bag
        // (same object). Preserve that aliasing across the clone so post() and
        // input() don't diverge after a with*/merge/replace.
        $jsonAliasedToRequest = $this->json !== null && $this->json === $this->request;

        $this->query = clone $this->query;
        $this->request = clone $this->request;
        $this->attributes = clone $this->attributes;
        $this->cookies = clone $this->cookies;
        $this->files = clone $this->files;
        $this->server = clone $this->server;
        $this->headers = clone $this->headers;

        if ($this->json !== null) {
            $this->json = $jsonAliasedToRequest ? $this->request : clone $this->json;
        }
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * Build a Request from PHP superglobals (classic SAPI entry point).
     * Swoole adapters construct the Request directly instead.
     */
    public static function capture(): static
    {
        static::enableHttpMethodParameterOverride();

        return static::createFromBase(SymfonyRequest::createFromGlobals());
    }

    /**
     * Build a Request from discrete components (Swoole / test adapters).
     *
     * Maps the framework's component-style inputs onto the Symfony engine so
     * non-SAPI transports never touch superglobals.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $files
     * @param array<string, scalar> $server
     */
    public static function build(
        string $method,
        string $path,
        array $headers = [],
        array $query = [],
        array $body = [],
        string $rawBody = '',
        array $cookies = [],
        array $files = [],
        array $server = [],
    ): static {
        $serverParams = $server;
        $serverParams['REQUEST_METHOD'] = strtoupper($method);
        $serverParams['REQUEST_URI'] ??= $path;

        // Adapters (e.g. Swoole) pass the path and query separately, so the
        // QUERY_STRING server param is absent — without it getQueryString() and
        // therefore fullUrl()/uri() would drop the query. Derive it from $query.
        if ($query !== [] && !isset($serverParams['QUERY_STRING'])) {
            $serverParams['QUERY_STRING'] = http_build_query($query);
        }

        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $serverParams[$key] = $value;
            if (strtolower($name) === 'content-type') {
                $serverParams['CONTENT_TYPE'] = $value;
            }
            if (strtolower($name) === 'content-length') {
                $serverParams['CONTENT_LENGTH'] = $value;
            }
        }

        $base = new SymfonyRequest($query, $body, [], $cookies, $files, $serverParams, $rawBody);

        return static::createFromBase($base);
    }

    /** Promote a base Symfony request into a kernel Request. */
    public static function createFromBase(SymfonyRequest $request): static
    {
        $new = new static(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            $request->getContent(),
        );

        $new->headers->replace($request->headers->all());

        if ($new->isJson()) {
            $new->request = $new->json();
        }

        return $new;
    }

    // ── Core accessors (GDA contract) ───────────────────────────────────────────

    public function method(): string
    {
        return $this->getMethod();
    }

    /** Path info with leading slash, e.g. "/api/invoices". */
    public function path(): string
    {
        return $this->getPathInfo();
    }

    /** Decoded, normalised path without surrounding slashes ("/" stays "/"). */
    public function decodedPath(): string
    {
        $path = trim(rawurldecode($this->getPathInfo()), '/');

        return $path === '' ? '/' : $path;
    }

    public function rawBody(): string
    {
        return $this->getContent();
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers->get($name, $default);
    }

    public function hasHeader(string $name): bool
    {
        return $this->headers->has($name);
    }

    /** @return array<string, mixed> */
    public function headersAll(): array
    {
        return $this->headers->all();
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies->get($name, $default);
    }

    public function hasCookie(string $name): bool
    {
        return $this->cookies->has($name);
    }

    /** @return array<string, string> */
    public function cookiesAll(): array
    {
        return $this->cookies->all();
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes->get($key, $default);
    }

    /** @return array<string, mixed> */
    public function attributesAll(): array
    {
        return $this->attributes->all();
    }

    public function identity(): ?Identity
    {
        return $this->identity;
    }

    public function container(): ?ModuleContainer
    {
        return $this->container;
    }

    // ── Input ───────────────────────────────────────────────────────────────────

    /** Active input source: JSON body for JSON requests, query for GET/HEAD, else body. */
    public function getInputSource(): InputBag
    {
        if ($this->isJson()) {
            return $this->json();
        }

        return \in_array($this->getRealMethod(), ['GET', 'HEAD'], true) ? $this->query : $this->request;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->getInputSource()->all()[$key]
            ?? $this->query->all()[$key]
            ?? $default;
    }

    /** @return array<string, mixed> merged body + query (+ files) */
    public function all(): array
    {
        return $this->getInputSource()->all() + $this->query->all();
    }

    /**
     * The parsed request BODY only (decoded JSON, or form fields) — excludes the
     * query string. Empty for bodyless methods. Convenient for DTO::fromRequest().
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query->all()[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function queryAll(): array
    {
        return $this->query->all();
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->request->all()[$key] ?? $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server->get($key, $default);
    }

    /** Decoded JSON body as an InputBag (empty bag when not JSON / unparsable). */
    public function json(): InputBag
    {
        if ($this->json === null) {
            $decoded = json_decode($this->getContent(), true);
            $this->json = new InputBag(\is_array($decoded) ? $decoded : []);
        }

        return $this->json;
    }

    public function has(string $key): bool
    {
        $all = $this->all();

        return \array_key_exists($key, $all);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return $value !== null && $value !== '' && $value !== [];
    }

    public function missing(string $key): bool
    {
        return !$this->has($key);
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return $value === null ? $default : (int) $value;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key);

        return $value === null ? $default : (float) $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key);

        return \is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param  string[] $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $all = $this->all();
        $out = [];
        foreach ($keys as $key) {
            if (\array_key_exists($key, $all)) {
                $out[$key] = $all[$key];
            }
        }

        return $out;
    }

    /**
     * @param  string[] $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    // ── Files ─────────────────────────────────────────────────────────────────

    public function file(string $key): ?UploadedFile
    {
        $file = $this->files->get($key);

        // Already a kernel UploadedFile (e.g. injected by the Swoole adapter in
        // test mode) — return as-is to preserve its move semantics.
        if ($file instanceof UploadedFile) {
            return $file;
        }

        // A real PHP-FPM upload — wrap it preserving is_uploaded_file() safety.
        return $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
            ? UploadedFile::createFromBase($file)
            : null;
    }

    public function hasFile(string $key): bool
    {
        $file = $this->files->get($key);

        return $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $file->getPathname() !== '';
    }

    // ── URL / connection ──────────────────────────────────────────────────────

    public function isMethod(string $method): bool
    {
        return $this->getMethod() === strtoupper($method);
    }

    public function isSecure(): bool
    {
        return parent::isSecure();
    }

    public function scheme(): string
    {
        return $this->getScheme();
    }

    public function host(): string
    {
        return $this->getHost();
    }

    public function url(): string
    {
        return rtrim(preg_replace('/\?.*/', '', $this->getUri()) ?? '', '/');
    }

    public function fullUrl(): string
    {
        $query = $this->getQueryString();

        return $query === null ? $this->url() : $this->url() . '?' . $query;
    }

    /**
     * Immutable PSR-7 view of the current full URL, for safe manipulation —
     * e.g. `$request->uri()->withPath('/login')->withQuery('')` to build a
     * redirect target, or `->withQuery('')` for a canonical URL. See Uri.
     */
    public function uri(): Uri
    {
        return Uri::fromRequest($this);
    }

    /**
     * Absolute-URL generator rooted at this request's scheme://host, for links
     * that must not hardcode the host (OAuth callbacks, email links, sitemaps) —
     * e.g. `$request->site()->to('auth/callback')`. See SiteUri.
     */
    public function site(): SiteUri
    {
        return SiteUri::fromRequest($this);
    }

    /**
     * Content negotiator over this request's Accept-* headers — pick the best
     * response language / media type / charset / encoding the client accepts,
     * e.g. `$request->negotiate()->language(['en', 'fr'])`. See Negotiate.
     */
    public function negotiate(): Negotiate
    {
        return Negotiate::for($this);
    }

    public function ip(): ?string
    {
        return $this->getClientIp();
    }

    public function userAgent(): ?string
    {
        return $this->headers->get('User-Agent');
    }

    public function contentType(): ?string
    {
        return $this->headers->get('Content-Type');
    }


    /** Extract a Bearer token from the Authorization header (no global lookups). */
    public function bearerToken(): ?string
    {
        $header = (string) $this->headers->get('Authorization', '');
        if (stripos($header, 'Bearer ') === 0) {
            $token = substr($header, 7);
            $token = str_contains($token, ',') ? strstr($token, ',', true) : $token;

            return $token !== '' ? trim((string) $token) : null;
        }

        return null;
    }

    // ── Path matching ───────────────────────────────────────────────────────────

    /** @return string[] non-empty path segments */
    public function segments(): array
    {
        return array_values(array_filter(
            explode('/', $this->decodedPath()),
            static fn($s): bool => $s !== '',
        ));
    }

    public function segment(int $index, ?string $default = null): ?string
    {
        return $this->segments()[$index - 1] ?? $default;
    }

    /** Match the path against shell-style wildcard patterns (e.g. "api/*"). */
    public function is(string ...$patterns): bool
    {
        $path = $this->decodedPath();
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern, '/') ?: '/';
            if ($pattern === $path) {
                return true;
            }
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    // ── Content negotiation ─────────────────────────────────────────────────────

    public function isJson(): bool
    {
        $type = (string) $this->headers->get('Content-Type');

        return str_contains($type, '/json') || str_contains($type, '+json');
    }

    public function isXmlHttpRequest(): bool
    {
        return parent::isXmlHttpRequest();
    }

    public function wantsJson(): bool
    {
        $acceptable = $this->getAcceptableContentTypes();
        $first = isset($acceptable[0]) ? strtolower($acceptable[0]) : '';

        return $first !== '' && (str_contains($first, '/json') || str_contains($first, '+json'));
    }

    public function expectsJson(): bool
    {
        return $this->isXmlHttpRequest() || $this->wantsJson() || $this->isJson();
    }

    /**
     * Pick the best supported content type against the Accept header; falls back
     * to the first supported type when nothing matches.
     *
     * @param  string[] $supported
     */
    public function accepts(array $supported): ?string
    {
        if ($supported === []) {
            return null;
        }
        $accepts = $this->getAcceptableContentTypes();
        if ($accepts === []) {
            return $supported[0];
        }
        foreach ($accepts as $accept) {
            if ($accept === '*/*' || $accept === '*') {
                return $supported[0];
            }
            foreach ($supported as $type) {
                if (strtolower($accept) === strtolower($type)) {
                    return $type;
                }
            }
        }

        return $supported[0];
    }

    // ── Immutable mutators (return clones) ────────────────────────────────────

    public function withHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->headers->set($name, $value);

        return $clone;
    }

    public function withAttribute(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->attributes->set($key, $value);

        return $clone;
    }

    public function withIdentity(Identity $identity): static
    {
        $clone = clone $this;
        $clone->identity = $identity;

        return $clone;
    }

    public function withContainer(ModuleContainer $container): static
    {
        $clone = clone $this;
        $clone->container = $container;

        return $clone;
    }

    /**
     * Return a NEW request with $input merged into the active input source.
     *
     * @param array<string, mixed> $input
     */
    public function merge(array $input): static
    {
        $clone = clone $this;
        $clone->getInputSource()->add($input);

        return $clone;
    }

    /**
     * Return a NEW request whose active input source is replaced by $input.
     *
     * @param array<string, mixed> $input
     */
    public function replace(array $input): static
    {
        $clone = clone $this;
        $clone->getInputSource()->replace($input);

        return $clone;
    }
}
