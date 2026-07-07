<?php

declare(strict_types=1);

namespace Plugins\User\Application\Ports;

/**
 * BreachChecker — screens a plaintext password against a known-breached
 * corpus (NIST 800-63B "compromised password" requirement).
 *
 * Implementations MUST fail OPEN: if the breach source is unreachable, return
 * false (treat as not-breached) so a third-party outage never blocks a user
 * from registering or changing their password.
 */
interface BreachChecker
{
    /** True when the password is known to have appeared in a breach corpus. */
    public function isBreached(string $password): bool;
}
