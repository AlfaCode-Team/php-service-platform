<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot;

/**
 * Thrown by an individual BootPipeline stage on validation failure.
 *
 * Lives in the Boot namespace because boot stages run before the rest of the
 * exception hierarchy is relevant. BootPipeline catches this and re-wraps it in
 * a BootFailureException annotated with the failing stage name.
 */
class BootException extends \RuntimeException {}
