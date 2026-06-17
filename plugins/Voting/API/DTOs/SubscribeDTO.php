<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Voting\Domain\ValueObjects\SubscriptionLevel;

final readonly class SubscribeDTO
{
    public function __construct(
        public string            $editionId,
        public SubscriptionLevel $level,
        public string            $redirectUrl,
    ) {}

    public static function fromRequest(Request $request, string $editionId): self
    {
        $rawLevel    = trim((string) $request->input('level', ''));
        $redirectUrl = trim((string) $request->input('redirect_url', ''));

        try {
            $level = SubscriptionLevel::from($rawLevel);
        } catch (\ValueError) {
            throw new ValidationException(['level' => 'level must be one of: silver, gold, platinum.']);
        }

        if ($level->isFree()) {
            throw new ValidationException(['level' => 'Cannot subscribe to the free level.']);
        }

        if ($redirectUrl === '') {
            throw new ValidationException(['redirect_url' => 'redirect_url is required.']);
        }

        return new self(
            editionId:   $editionId,
            level:       $level,
            redirectUrl: $redirectUrl,
        );
    }
}
