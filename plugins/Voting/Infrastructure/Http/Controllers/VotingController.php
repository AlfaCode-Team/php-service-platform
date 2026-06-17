<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Voting\API\Contracts\VotingServiceContract;
use Plugins\Voting\API\DTOs\CastVoteDTO;

final class VotingController
{
    public function __construct(
        private readonly VotingServiceContract $service,
    ) {}

    public function leaderboard(Request $request, string $id): Response
    {
        $categoryId  = trim((string) $request->query('category_id', '')) ?: null;
        $contestants = $this->service->leaderboard($id, $categoryId);
        return Response::json(['contestants' => array_map(fn($c) => $c->toArray(), $contestants)]);
    }

    public function castVote(Request $request, string $id): Response
    {
        $dto    = CastVoteDTO::fromRequest($request, $id);
        $result = $this->service->castVote($dto);
        return Response::json($result->toArray());
    }
}
