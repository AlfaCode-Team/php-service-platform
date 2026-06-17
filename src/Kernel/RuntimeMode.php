<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel;

/**
 * RuntimeMode — the surface a kernel is materialized for.
 *
 * build() compiles manifests but constructs no pipelines. The first call to an
 * entry point (http()/cli()/workerLoop()) materializes the kernel for that
 * surface: pipelines are constructed, modules are wired ONCE, and the core
 * container is frozen. Only the surface actually used does any real work — the
 * manifest-backed pipelines defer their disk I/O until their own first run, so
 * a process that only ever serves HTTP never reads the job manifest, and a CLI
 * process never reads the route manifest.
 */
enum RuntimeMode: string
{
    case Http   = 'http';
    case Cli    = 'cli';
    case Worker = 'worker';
}
