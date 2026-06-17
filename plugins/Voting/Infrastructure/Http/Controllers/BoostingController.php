<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Voting\API\Contracts\BoostingServiceContract;
use Plugins\Voting\API\DTOs\InitiateBoostDTO;

final class BoostingController
{
    public function __construct(
        private readonly BoostingServiceContract $service,
    ) {}

    public function initiate(Request $request, string $id): Response
    {
        $dto = InitiateBoostDTO::fromRequest($request, $id);
        return Response::json($this->service->initiate($dto)->toArray(), 201);
    }

    public function confirm(Request $request): Response
    {
        $txRef = trim((string) $request->input('tx_ref', ''));
        return Response::json($this->service->confirm($txRef)->toArray());
    }
}
