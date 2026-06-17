<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityGateway;

final class SecurityStage implements HttpStageContract
{
    public function __construct(
        private readonly SecurityGateway $gateway
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $verdict = $this->gateway->inspect($request);

        if ($verdict->isDenied()) {
            return Response::json([
                'error' => [
                    'code' => 'security.denied',
                    'message' => $verdict->reason(),
                    'requestId' => $request->attribute('correlation_id'),
                ],
            ], $verdict->statusCode());
        }

        if ($verdict->identity() !== null) {
            $request = $request->withIdentity($verdict->identity());
        }

        return $next($request);
    }
}
