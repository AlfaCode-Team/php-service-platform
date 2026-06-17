<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Voting\API\Contracts\EditionSettingsServiceContract;
use Plugins\Voting\API\DTOs\UpdateEditionSettingsDTO;

final class EditionSettingsController
{
    public function __construct(
        private readonly EditionSettingsServiceContract $service,
    ) {}

    public function show(Request $request, string $id): Response
    {
        return Response::json($this->service->get($id)->toArray());
    }

    public function update(Request $request, string $id): Response
    {
        $dto = UpdateEditionSettingsDTO::fromRequest($request);
        return Response::json($this->service->update($id, $dto)->toArray());
    }
}
