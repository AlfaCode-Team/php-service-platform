<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Http;

/**
 * Method — the HTTP request methods, with semantic helpers.
 *
 * Replaces stringly-typed method comparisons across the pipeline. Use
 * Method::from($request->method()) to lift a Request into a typed value.
 */
enum Method: string
{
    case CONNECT = 'CONNECT';
    case DELETE  = 'DELETE';
    case GET     = 'GET';
    case HEAD    = 'HEAD';
    case OPTIONS = 'OPTIONS';
    case PATCH   = 'PATCH';
    case POST    = 'POST';
    case PUT     = 'PUT';
    case TRACE   = 'TRACE';

    /** Safe methods do not mutate state (RFC 9110 §9.2.1). */
    public function isSafe(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS, self::TRACE => true,
            default => false,
        };
    }

    /** Idempotent methods may be retried without additional effect. */
    public function isIdempotent(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS, self::TRACE, self::PUT, self::DELETE => true,
            default => false,
        };
    }

    /** Whether responses to this method are cacheable by default. */
    public function isCacheable(): bool
    {
        return $this === self::GET || $this === self::HEAD;
    }

    /** @return list<string> all method values */
    public static function all(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
