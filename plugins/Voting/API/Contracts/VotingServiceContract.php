<?php

declare(strict_types=1);

namespace Plugins\Voting\API\Contracts;

use Plugins\Voting\API\DTOs\CastVoteDTO;
use Plugins\Voting\API\DTOs\ContestantDTO;

interface VotingServiceContract
{
    /** @return list<ContestantDTO> */
    public function leaderboard(string $editionId, ?string $categoryId = null): array;

    public function castVote(CastVoteDTO $dto): ContestantDTO;
}
