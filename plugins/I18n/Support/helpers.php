<?php

declare(strict_types=1);

use Plugins\I18n\Support\Lang;

/**
 * Global translation helpers for the I18n plugin.
 *
 * These delegate to the request-scoped Translator bound by LocaleStage. When no
 * translator is active (CLI/worker, or a route that did not load the I18n
 * module) they degrade gracefully: __()/trans() return the key, trans_choice()
 * returns the raw line, and lang_has() returns false. Translation never throws.
 */

if (!function_exists('trans')) {
    /**
     * Translate a "group.key" message with :placeholder substitution.
     *
     * The key is dotted: the first segment is the lang file (group) and the rest
     * indexes into its returned array — 'validation.required' reads
     * lang/{locale}/validation.php ['required']. Placeholders are filled in three
     * cases: 'name' fills :name, :Name and :NAME. Longest keys are applied first
     * so :min never corrupts :minutes. A missing key returns the key unchanged.
     *
     * Usage:
     *   trans('validation.required', ['field' => 'email']);
     *   // => "The email field is required."
     *
     *   trans('checkout.greeting', ['name' => 'sam']);   // ':Name' in the line
     *   // => "Welcome, Sam"
     *
     *   trans('report.title', locale: 'fr');             // force a locale
     *
     *   trans('missing.key');                            // => "missing.key"
     *
     * @param array<string,string|int|float> $replace Placeholder => value map.
     * @param ?string                        $locale  Override the active locale.
     */
    function trans(string $key, array $replace = [], ?string $locale = null): string
    {
        return Lang::translator()?->get($key, $replace, $locale) ?? $key;
    }
}

if (!function_exists('__')) {
    /**
     * Alias of trans() — the terse form for use inside views and messages.
     *
     * Usage:
     *   __('validation.email', ['field' => 'email']);
     *   // => "The email field must be a valid email address."
     *
     *   echo __('nav.home');                             // => "Home"
     *
     * @param array<string,string|int|float> $replace Placeholder => value map.
     * @param ?string                        $locale  Override the active locale.
     */
    function __(string $key, array $replace = [], ?string $locale = null): string
    {
        return trans($key, $replace, $locale);
    }
}

if (!function_exists('trans_choice')) {
    /**
     * Pluralize a "group.key" message for a given count.
     *
     * The resolved line is split on '|' into forms. Simple form is
     * "singular|plural" (count === 1 picks the first, otherwise the second).
     * Explicit ranges take priority: '{0}' matches exactly zero, '[1,19]'
     * matches an inclusive range, '[20,*]' matches 20-or-more. ':count' is always
     * available as a replacement, alongside any you pass.
     *
     * Usage:
     *   // lang line: 'apple|apples'
     *   trans_choice('cart.apples', 1);                  // => "apple"
     *   trans_choice('cart.apples', 5);                  // => "apples"
     *
     *   // lang line: '{0} No items|[1,*] :count item(s) in :cart'
     *   trans_choice('cart.items', 0, ['cart' => 'bag']);   // => "No items"
     *   trans_choice('cart.items', 3, ['cart' => 'bag']);   // => "3 item(s) in bag"
     *
     * @param int                            $count   Drives which form is chosen.
     * @param array<string,string|int|float> $replace Extra placeholders (:count is auto).
     * @param ?string                        $locale  Override the active locale.
     */
    function trans_choice(string $key, int $count, array $replace = [], ?string $locale = null): string
    {
        return Lang::translator()?->choice($key, $count, $replace, $locale) ?? $key;
    }
}

if (!function_exists('lang_has')) {
    /**
     * Check whether a translation key resolves to a string in the active (or
     * given) locale. Does not consult the fallback locale — it tests the target
     * locale only, which is what you want when deciding to render an optional,
     * locale-specific block.
     *
     * Usage:
     *   if (lang_has('promo.banner')) {
     *       echo __('promo.banner');
     *   }
     *
     *   lang_has('promo.banner', 'fr');                  // test the French file
     *
     * @param ?string $locale Override the active locale.
     */
    function lang_has(string $key, ?string $locale = null): bool
    {
        return Lang::translator()?->has($key, $locale) ?? false;
    }
}
