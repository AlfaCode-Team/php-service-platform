<?php

declare(strict_types=1);

use Plugins\Pageflow\Http\PageflowShares;

if (!function_exists('pageflow_share')) {
    /**
     * Register shared Pageflow props, present on every rendered page.
     *
     * Call ONCE at bootstrap (or a plugin's boot) — you register a definition,
     * not a value; the value is resolved per request. Two forms:
     *
     *   // keyed share — resolver receives the current Request
     *   pageflow_share('auth', fn($request) => $request->identity()?->userId);
     *   pageflow_share('year', fn() => date('Y'));
     *
     *   // raw contributor — full control, share()/mergeShared() many keys
     *   pageflow_share(function ($request, $responder) {
     *       $responder->mergeShared(['appName' => 'HKM', 'locale' => 'en']);
     *   });
     *
     * @param string|callable $key      Share key, or a raw contributor callable
     *                                   fn(Request, PageflowResponder): void.
     * @param callable|null   $resolver When $key is a string: fn(Request): mixed.
     */
    function pageflow_share(string|callable $key, ?callable $resolver = null): void
    {
        if (!is_string($key)) {
            PageflowShares::add($key);
            return;
        }

        if ($resolver === null) {
            throw new InvalidArgumentException(
                'pageflow_share(string $key, callable $resolver): a resolver is required when a key is given.'
            );
        }

        PageflowShares::key($key, $resolver);
    }
}
