<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};

/** Every HTTP pipeline stage implements this. */
interface HttpStageContract
{
    public function handle(Request $request, callable $next): Response;
}
