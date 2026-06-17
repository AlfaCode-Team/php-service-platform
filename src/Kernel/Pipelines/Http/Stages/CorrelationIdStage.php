<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

final class CorrelationIdStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $id = $request->header('X-Request-ID')
            ?? $request->header('X-Correlation-ID')
            ?? sprintf('%s-%s', date('Ymd'), bin2hex(random_bytes(8)));

        $request = $request
            ->withHeader('X-Correlation-ID', $id)
            ->withAttribute('correlation_id', $id);

        return $next($request)->withHeader('X-Correlation-ID', $id);
    }
}
