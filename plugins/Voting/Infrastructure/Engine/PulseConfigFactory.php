<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Engine;

use AlfaCode\PulseEngine\Config\VotingConfig;

/**
 * PulseConfigFactory — builds a pulse-engine VotingConfig from the plugin's
 * declared environment variables (see module.json "config").
 *
 * Centralising construction here keeps the Provider thin and gives the engine
 * a single, validated configuration source.
 */
final class PulseConfigFactory
{
    public static function fromEnvironment(): VotingConfig
    {
        $env = static fn (string $key, string $default = ''): string =>
            (string) (env($key) ?: ($_ENV[$key] ?? $default));

        $secret = $env('VOTING_HMAC_SECRET');
        if ($secret === '') {
            // VotingConfig rejects an empty secret. Derive a deterministic, app
            // scoped fallback so local/dev boots succeed without extra setup;
            // production MUST set VOTING_HMAC_SECRET explicitly.
            $secret = hash('sha256', 'voting-plugin:' . ($env('APP_KEY', 'local')));
        }

        return new VotingConfig([
            'security' => [
                'max_per_minute' => (int) $env('VOTING_RATE_LIMIT_PER_MINUTE', '5'),
                'window_seconds' => (int) $env('VOTING_RATE_LIMIT_WINDOW_SEC', '60'),
                'secret_key'     => $secret,
            ],
            'pricing' => [
                'tier1_max'  => (int) $env('VOTING_PRICE_TIER1_MAX', '20'),
                'tier1_kobo' => (int) $env('VOTING_PRICE_TIER1_KOBO', '1000'),
                'tier2_max'  => (int) $env('VOTING_PRICE_TIER2_MAX', '100'),
                'tier2_kobo' => (int) $env('VOTING_PRICE_TIER2_KOBO', '800'),
                'tier3_kobo' => (int) $env('VOTING_PRICE_TIER3_KOBO', '500'),
            ],
            'features' => [
                'free_vote' => filter_var($env('VOTING_FREE_VOTE_ENABLED', 'true'), FILTER_VALIDATE_BOOL),
            ],
        ]);
    }
}
