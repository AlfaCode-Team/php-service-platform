<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Voting\API\Contracts\SubscriptionServiceContract;
use Plugins\Voting\API\DTOs\SubscribeDTO;

final class SubscriptionController
{
    public function __construct(
        private readonly SubscriptionServiceContract $service,
    ) {}

    public function show(Request $request, string $id): Response
    {
        return Response::json($this->service->get($id)->toArray());
    }

    public function subscribe(Request $request, string $id): Response
    {
        $dto = SubscribeDTO::fromRequest($request, $id);
        return Response::json($this->service->subscribe($dto)->toArray(), 201);
    }

    public function confirm(Request $request): Response
    {
        $txRef = trim((string) $request->input('tx_ref', ''));
        return Response::json($this->service->confirm($txRef)->toArray());
    }
}
