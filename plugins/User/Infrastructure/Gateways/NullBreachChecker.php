<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Gateways;

use Plugins\User\Application\Ports\BreachChecker;

/**
 * NullBreachChecker — the no-op breach checker.
 *
 * Bound when breach screening is disabled (USER_BREACH_CHECK off) or no
 * HttpClientPort is available, so UserService can always depend on a
 * BreachChecker without a null check.
 */
final class NullBreachChecker implements BreachChecker
{
    public function isBreached(string $password): bool
    {
        return false;
    }
}
