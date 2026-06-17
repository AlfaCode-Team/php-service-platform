<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Container;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Thrown when a container cannot resolve the requested id.
 * Implements the PSR-11 NotFound contract for interop.
 */
final class EntryNotFoundException extends \RuntimeException implements NotFoundExceptionInterface, ContainerExceptionInterface
{
}
