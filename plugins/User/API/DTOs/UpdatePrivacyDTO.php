<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\User\Domain\ValueObjects\ProfileVisibility;

/**
 * Validated privacy-update input (idempotent full replace). User id comes from
 * the Identity, never the body.
 */
final readonly class UpdatePrivacyDTO
{
    public function __construct(
        public ProfileVisibility $profileVisibility,
        public bool $showPhone,
        public bool $showEmail,
        public bool $marketingOptIn,
        public bool $analyticsOptIn,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $visibility = ProfileVisibility::Public;
        try {
            $visibility = ProfileVisibility::fromString((string) $request->input('profileVisibility', 'public'));
        } catch (\DomainException $e) {
            throw new ValidationException(['profileVisibility' => $e->getMessage()]);
        }

        return new self(
            profileVisibility: $visibility,
            showPhone:         $request->boolean('showPhone'),
            showEmail:         $request->boolean('showEmail'),
            marketingOptIn:    $request->boolean('marketingOptIn'),
            analyticsOptIn:    $request->boolean('analyticsOptIn'),
        );
    }
}
