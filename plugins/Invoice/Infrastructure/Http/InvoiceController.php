<?php

declare(strict_types=1);

namespace Plugins\Invoice\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Invoice\API\Contracts\InvoiceServiceContract;

final class InvoiceController
{
    public function __construct(
        private readonly InvoiceServiceContract $service,
    ) {}

    public function index(Request $request): Response
    {
        return Response::json($this->service->all());
    }

    public function show(Request $request, string $id): Response
    {
        return Response::json($this->service->find($id));
    }
}
