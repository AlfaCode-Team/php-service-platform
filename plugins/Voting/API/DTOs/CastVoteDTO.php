<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

final readonly class CastVoteDTO
{
    public function __construct(
        public string $contestantId,
        public string $ipAddress,
    ) {}

    public static function fromRequest(Request $request, string $contestantId): self
    {
        $contestantId = trim($contestantId);

        if ($contestantId === '') {
            throw new ValidationException(['contestant_id' => 'Contestant ID is required.']);
        }

        $ip = $request->header('x-forwarded-for')
            ?? $request->header('x-real-ip')
            ?? '';

        return new self(contestantId: $contestantId, ipAddress: $ip);
    }
}
