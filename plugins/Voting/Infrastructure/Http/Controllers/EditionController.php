<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Voting\API\Contracts\EditionServiceContract;
use Plugins\Voting\API\DTOs\AddContestantDTO;
use Plugins\Voting\API\DTOs\CreateEditionDTO;
use Plugins\Voting\API\DTOs\UpdateEditionDTO;

final class EditionController
{
    public function __construct(
        private readonly EditionServiceContract $service,
    ) {}

    public function index(Request $request): Response
    {
        return Response::json(['editions' => array_map(fn($e) => $e->toArray(), $this->service->list())]);
    }

    public function show(Request $request, string $id): Response
    {
        $edition = $this->service->find($id);
        if ($edition === null) {
            return Response::notFound();
        }
        return Response::json($edition->toArray());
    }

    public function create(Request $request): Response
    {
        $dto = CreateEditionDTO::fromRequest($request);
        return Response::json($this->service->create($dto)->toArray(), 201);
    }

    public function update(Request $request, string $id): Response
    {
        $dto = UpdateEditionDTO::fromRequest($request);
        return Response::json($this->service->update($id, $dto)->toArray());
    }

    public function activate(Request $request, string $id): Response
    {
        return Response::json($this->service->activate($id)->toArray());
    }

    public function close(Request $request, string $id): Response
    {
        return Response::json($this->service->close($id)->toArray());
    }

    public function addContestant(Request $request, string $id): Response
    {
        $dto = AddContestantDTO::fromRequest($request);
        return Response::json($this->service->addContestant($id, $dto)->toArray(), 201);
    }

    public function removeContestant(Request $request, string $id): Response
    {
        $this->service->removeContestant($id);
        return Response::empty(204);
    }
}
