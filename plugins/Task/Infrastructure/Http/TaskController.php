<?php

declare(strict_types=1);

namespace Plugins\Task\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use Plugins\Task\API\Contracts\TaskServiceContract;
use Plugins\Task\API\DTOs\CreateTaskDTO;
use Plugins\View\API\Contracts\ViewRendererContract;

final class TaskController
{
    public function __construct(
        private readonly TaskServiceContract $tasks,
        private readonly ViewRendererContract $view,
    ) {
    }
 
    public function index(Request $request): Response
    {
        return Response::html(
            $this->view->setVar('name', 'Hakeem')
                ->render('welcome', ['layout' => 'layouts/app'])
        );

        // $tasks = array_map(static fn($t) => $t->toArray(), $this->tasks->list());
        // return Response::json(['data' => $tasks]);
    }

    public function create(Request $request): Response
    {
        $task = $this->tasks->create(CreateTaskDTO::fromRequest($request));
        return Response::json(['data' => $task->toArray()], 201);
    }

    public function show(Request $request, string $id): Response
    {
        $task = $this->tasks->find($id);
        return $task === null
            ? Response::notFound("Task [{$id}] not found.")
            : Response::json(['data' => $task->toArray()]);
    }

    public function complete(Request $request, string $id): Response
    {
        $task = $this->tasks->complete($id);
        return $task === null
            ? Response::notFound("Task [{$id}] not found.")
            : Response::json(['data' => $task->toArray()]);
    }

    public function destroy(Request $request, string $id): Response
    {
        return $this->tasks->delete($id)
            ? Response::empty(204)
            : Response::notFound("Task [{$id}] not found.");
    }
}
