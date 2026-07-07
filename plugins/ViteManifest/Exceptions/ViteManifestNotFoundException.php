<?php

declare(strict_types=1);

namespace Plugins\ViteManifest\Exceptions;

use RuntimeException;

/**
 * Thrown when a production build manifest cannot be located — usually because
 * `npm run build` has not run for the active surface, or the public path /
 * build directory is misconfigured.
 */
final class ViteManifestNotFoundException extends RuntimeException
{
}
