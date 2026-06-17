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

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * @param array<string,string|int|float> $replace
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

    public function has(string $key, ?string $locale = null): bool
    {
        return is_string($this->lookup($key, $locale ?? $this->locale));
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

    /** @param array<string,string|int|float> $replace */
    private function interpolate(string $line, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $line = str_replace(':' . $key, (string) $value, $line);
        }
        return $line;
    }
}
