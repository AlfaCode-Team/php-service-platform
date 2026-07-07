<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

/**
 * SupportsBasicAuth — HTTP Basic authentication capability for a guard. Port of
 * the old __DEV__ SupportsBasicAuth contract. Returns null on success (proceed)
 * or a 401 challenge Response on failure.
 */
interface SupportsBasicAuth
{
    /** Authenticate + persist a session from the Basic header. */
    public function basic(string $field = 'email', array $extraConditions = []): ?Response;

    /** Authenticate for a single request from the Basic header (no session). */
    public function onceBasic(string $field = 'email', array $extraConditions = []): ?Response;
}
