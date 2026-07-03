<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

/**
 * Registry of shared-prop CONTRIBUTORS — the app-lifetime definitions behind the
 * pageflow_share() helper.
 *
 * Contributors are closures registered ONCE at bootstrap (like route/filter
 * definitions), never per-request values. The stored closures are stateless
 * definitions; the actual values are computed inside them per request, so this
 * static registry is safe under OpenSwoole — no request state leaks between
 * requests. Do NOT push already-computed request data in here.
 */
final class PageflowShares
{
    /** @var list<callable(Request, PageflowResponder): void> */
    private static array $contributors = [];

    /** Register a raw contributor: fn(Request, PageflowResponder): void. */
    public static function add(callable $contributor): void
    {
        self::$contributors[] = $contributor;
    }

    /**
     * Register one keyed share whose value is resolved per request.
     *
     * @param callable(Request): mixed $resolver
     */
    public static function key(string $key, callable $resolver): void
    {
        self::$contributors[] = static function (Request $request, PageflowResponder $responder) use ($key, $resolver): void {
            $responder->share($key, $resolver($request));
        };
    }

    /** @return list<callable(Request, PageflowResponder): void> */
    public static function all(): array
    {
        return self::$contributors;
    }

    /** Clear all registered contributors (tests / re-bootstrap). */
    public static function flush(): void
    {
        self::$contributors = [];
    }
}
