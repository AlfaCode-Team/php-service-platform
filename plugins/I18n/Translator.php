<?php

declare(strict_types=1);

namespace Plugins\I18n;

/**
 * Minimal file-based translator.
 *
 * Lang files live at {dir}/{locale}/{group}.php and return a nested array:
 *
 *   // lang/en/validation.php
 *   return ['required' => 'The :field field is required.', ...];
 *
 * Lookups use "group.key" dotted notation and :placeholder substitution:
 *
 *   $t->get('validation.required', ['field' => 'email']);
 *
 * Missing keys fall back to the configured fallback locale, then to the key
 * itself — translation never throws.
 */
final class Translator
{
    /** @var array<string,array<string,mixed>> loaded [locale => group => data] */
    private array $loaded = [];

    public function __construct(
        private readonly string $directory,
        private string $locale = 'en',
        private readonly string $fallback = 'en',
    ) {
    }

    /**
     * Switch the active locale for subsequent lookups. Called once per request
     * by LocaleStage after negotiating Accept-Language; a per-call $locale on
     * get()/choice()/has() overrides this without mutating it.
     *
     * Usage:
     *   $translator->setLocale('fr');
     *   $translator->get('validation.required', ['field' => 'e-mail']);
     */
    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * The currently active locale.
     *
     * Usage:
     *   $translator->locale();   // => "en"
     */
    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Translate a "group.key" message with :placeholder substitution.
     *
     * The first dotted segment names the lang file (group); the rest indexes
     * into its returned array — 'validation.required' reads
     * {dir}/{locale}/validation.php ['required']. A missing key falls back to the
     * configured fallback locale, then returns the key itself (never throws,
     * never an empty string). Placeholders fill three cases: 'name' fills :name,
     * :Name and :NAME, and longer keys win so :min cannot corrupt :minutes.
     *
     * Usage:
     *   $t->get('validation.required', ['field' => 'email']);
     *   // => "The email field is required."
     *
     *   $t->get('report.title', locale: 'fr');   // force a locale for one call
     *
     *   $t->get('nope.missing');                 // => "nope.missing"
     *
     * @param array<string,string|int|float> $replace Placeholder => value map.
     * @param ?string                        $locale  Override the active locale.
     */
    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= $this->locale;

        $value = $this->lookup($key, $locale);
        if ($value === null && $locale !== $this->fallback) {
            $value = $this->lookup($key, $this->fallback);
        }
        if (!is_string($value)) {
            return $key; // unresolved — surface the key rather than empty string
        }

        return $this->interpolate($value, $replace);
    }

    /**
     * Whether a key resolves to a string in the given (or active) locale. Tests
     * that locale ONLY — it does not consult the fallback — so it answers "does
     * this locale actually define this message?".
     *
     * Usage:
     *   if ($t->has('promo.banner')) { echo $t->get('promo.banner'); }
     *
     *   $t->has('promo.banner', 'fr');   // test the French file specifically
     *
     * @param ?string $locale Override the active locale.
     */
    public function has(string $key, ?string $locale = null): bool
    {
        return is_string($this->lookup($key, $locale ?? $this->locale));
    }

    /**
     * Pluralize a message. The resolved line is split on '|' into forms selected
     * by $count, with optional range prefixes:
     *
     *   'apple|apples'                        // count === 1 → first, else second
     *   '{0} none|[1,19] some|[20,*] many'    // exact count / inclusive ranges
     *
     * ':count' is always available as a replacement, alongside any you pass.
     *
     * Usage:
     *   // lang line: 'apple|apples'
     *   $t->choice('cart.apples', 1);                        // => "apple"
     *   $t->choice('cart.apples', 5);                        // => "apples"
     *
     *   // lang line: '{0} No items|[1,*] :count item(s) in :cart'
     *   $t->choice('cart.items', 0, ['cart' => 'bag']);      // => "No items"
     *   $t->choice('cart.items', 3, ['cart' => 'bag']);      // => "3 item(s) in bag"
     *
     * @param int                            $count   Drives which form is chosen.
     * @param array<string,string|int|float> $replace Extra placeholders (:count is auto).
     * @param ?string                        $locale  Override the active locale.
     */
    public function choice(string $key, int $count, array $replace = [], ?string $locale = null): string
    {
        $line    = $this->get($key, [], $locale);
        $replace = ['count' => $count] + $replace;

        return $this->interpolate($this->selectPluralForm($line, $count), $replace);
    }

    private function lookup(string $key, string $locale): mixed
    {
        [$group, $item] = array_pad(explode('.', $key, 2), 2, null);
        if ($group === null || $item === null) {
            return null;
        }

        $data = $this->loadGroup($locale, $group);

        $value = $data;
        foreach (explode('.', $item) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function loadGroup(string $locale, string $group): array
    {
        if (isset($this->loaded[$locale][$group])) {
            return $this->loaded[$locale][$group];
        }

        // Defend against path traversal in locale/group segments.
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $locale) || !preg_match('/^[A-Za-z0-9_\-]+$/', $group)) {
            return $this->loaded[$locale][$group] = [];
        }

        $path = $this->directory . '/' . $locale . '/' . $group . '.php';
        $data = is_file($path) ? require $path : [];

        return $this->loaded[$locale][$group] = is_array($data) ? $data : [];
    }

    /**
     * Substitute :placeholder tokens. Keys are applied longest-first so a short
     * name (:min) can never clobber a longer one that shares its prefix
     * (:minutes). Each key also honours capitalized variants — :Field and
     * :FIELD produce "Value" and "VALUE" respectively.
     *
     * @param array<string,string|int|float> $replace
     */
    private function interpolate(string $line, array $replace): string
    {
        if ($replace === []) {
            return $line;
        }

        // Longest key first — prevents ":min" corrupting ":minutes".
        uksort($replace, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $pairs = [];
        foreach ($replace as $key => $value) {
            $value = (string) $value;
            $pairs[':' . $key]            = $value;
            $pairs[':' . ucfirst($key)]   = ucfirst($value);
            $pairs[':' . strtoupper($key)] = strtoupper($value);
        }

        return strtr($line, $pairs);
    }

    /**
     * Pick the correct segment of a '|'-delimited plural string for $count.
     * Supports Laravel-style range prefixes: '{0}', '[1,19]', '[20,*]'.
     */
    private function selectPluralForm(string $line, int $count): string
    {
        $segments = explode('|', $line);

        // Explicit range/exact prefixes take priority.
        foreach ($segments as $segment) {
            if (preg_match('/^\s*(?:\{(\d+)\}|\[(\d+),(\d+|\*)\])\s*/', $segment, $m) === 1) {
                $matches = isset($m[1]) && $m[1] !== ''
                    ? (int) $m[1] === $count
                    : (int) $m[2] <= $count && ($m[3] === '*' || $count <= (int) $m[3]);

                if ($matches) {
                    return trim(substr($segment, strlen($m[0])));
                }
            }
        }

        // Simple "singular|plural" fallback (no prefixes).
        $plain = array_values(array_filter(
            $segments,
            static fn(string $s): bool => preg_match('/^\s*(?:\{\d+\}|\[\d+,(?:\d+|\*)\])/', $s) !== 1,
        ));

        if ($plain === []) {
            return $line;
        }

        return $count === 1 ? $plain[0] : ($plain[1] ?? $plain[0]);
    }
}
