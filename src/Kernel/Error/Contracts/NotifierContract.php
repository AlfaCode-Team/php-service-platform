<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Error\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorContext;

/**
 * Every error notifier implements this contract.
 *
 * A notifier MUST NOT throw. If delivery fails it should swallow the failure
 * (the ErrorPipeline still runs the guaranteed fallback notifier). The whole
 * point of the error pipeline is that it never makes a bad situation worse.
 */
interface NotifierContract
{
    /** Stable identifier used in ErrorPipeline::rules(), e.g. 'slack', 'file'. */
    public function name(): string;

    public function notify(ErrorContext $context): void;
}
