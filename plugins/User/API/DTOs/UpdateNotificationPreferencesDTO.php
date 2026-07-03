<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\User\Domain\Entities\UserNotificationPreferences;

/**
 * Validated notification-preferences input. User id comes from the Identity.
 *
 * Accepts EITHER a nested { flags: { push: { messages: true, … } } } object
 * (matching the GET shape) OR flat top-level "push_messages" keys. Only flags
 * actually present are forwarded; the entity merges them over the defaults, so a
 * partial payload never silently disables an omitted channel (security topics
 * stay on unless explicitly turned off). Unknown flag keys are rejected (422).
 */
final readonly class UpdateNotificationPreferencesDTO
{
    /** @param array<string,bool> $flags */
    public function __construct(
        public array $flags,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $provided = [];
        $nested   = $request->input('flags');

        foreach (array_keys(UserNotificationPreferences::FLAG_DEFAULTS) as $key) {
            [$channel, $topic] = explode('_', $key, 2);

            if (is_array($nested)) {
                if (isset($nested[$channel]) && is_array($nested[$channel])
                    && array_key_exists($topic, $nested[$channel])) {
                    $provided[$key] = self::toBool($nested[$channel][$topic]);
                }
                continue;
            }

            // Flat fallback: only forward keys the client actually sent.
            if ($request->has($key)) {
                $provided[$key] = $request->boolean($key);
            }
        }

        if ($nested !== null && !is_array($nested)) {
            throw new ValidationException(['flags' => 'flags must be an object of channel → topic booleans.']);
        }

        return new self($provided);
    }

    private static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
