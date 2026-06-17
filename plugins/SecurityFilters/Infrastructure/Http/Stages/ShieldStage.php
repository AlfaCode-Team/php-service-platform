<?php

declare(strict_types=1);

namespace Plugins\SecurityFilters\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * Role / permission authorization gate (GDA rewrite of the Shield filter).
 *
 * In the 0.3 framework this was applied per-route via `shield:role@admin`.
 * GDA keeps routes in module.json, so requirements are declared as path-prefix
 * rules in the SHIELD_RULES env var and matched by the router path:
 *
 *   SHIELD_RULES="/admin=role:admin;/api/users=perm:users.edit"
 *
 * The longest matching prefix wins. Requirements are checked against the
 * kernel Identity attached by the SecurityGateway.
 */
final class ShieldStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $rule = $this->matchRule($request->path());
        if ($rule === null) {
            return $next($request);
        }

        $identity = $request->identity();
        if ($identity === null || $identity->isGuest()) {
            return Response::unauthorized('Authentication required for this resource.');
        }

        [$type, $value] = $rule;
        $ok = $type === 'role'
            ? $identity->hasRole($value)
            : $identity->hasPermission($value);

        if (!$ok) {
            return Response::forbidden('You do not have the required permissions to access this resource.');
        }

        return $next($request);
    }

    /**
     * @return array{0:string,1:string}|null  ['role'|'perm', value]
     */
    private function matchRule(string $path): ?array
    {
        $raw = (string) (env('SHIELD_RULES') ?: '');
        if ($raw === '') {
            return null;
        }

        $best = null;
        $bestLen = -1;
        foreach (explode(';', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || !str_contains($entry, '=')) {
                continue;
            }
            [$prefix, $req] = array_map('trim', explode('=', $entry, 2));
            if ($prefix === '' || !str_starts_with($path, $prefix)) {
                continue;
            }
            if (strlen($prefix) <= $bestLen) {
                continue;
            }
            if (str_starts_with($req, 'role:')) {
                $best = ['role', substr($req, 5)];
            } elseif (str_starts_with($req, 'perm:')) {
                $best = ['perm', substr($req, 5)];
            } else {
                continue;
            }
            $bestLen = strlen($prefix);
        }

        return $best;
    }
}
