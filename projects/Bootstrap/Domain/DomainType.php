<?php

declare(strict_types=1);

namespace Project\Bootstrap\Domain;

/**
 * DomainType — the "face" a resolved host presents.
 *
 * String values are stable identifiers consumed by the project layer
 * (route manifests, module gates, navigation). Add a new case here when
 * you introduce a new face, and update projects/platform.json accordingly.
 */
enum DomainType: string
{
    /** Subdomain registered as admin (e.g. 'app.*') — admin panel + project routes. */
    case Admin = 'admin';

    /** Subdomain registered as api (e.g. 'api.*') — API-only platform + project routes. */
    case Api = 'api';

    /** Project domain with no admin/api subdomain — project routes only. */
    case Project = 'project';

    /** Public-facing platform — no project matched, public route set. */
    case Public = 'public';
}
