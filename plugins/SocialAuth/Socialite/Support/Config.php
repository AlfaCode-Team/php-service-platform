<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Socialite\Support;

/**
 * Simple dotted-key config accessor for OAuth provider credentials.
 *
 * Expected shape:
 *   ['services' => ['github' => ['client_id' => ..., 'client_secret' => ..., 'redirect' => ...], ...]]
 */
final class Config
{
    /** @param array<string,mixed> $items */
    public function __construct(private readonly array $items = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }
        return $value;
    }
}
