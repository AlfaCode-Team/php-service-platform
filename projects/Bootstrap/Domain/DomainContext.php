<?php

declare(strict_types=1);

namespace Project\Bootstrap\Domain;

/**
 * DomainContext — resolved once per HTTP request from the Host header
 * + projects/platform.json + projects/projects.json.
 *
 * Carries everything the project layer needs to know about which project
 * and which face is being served. Attached to the kernel Request via
 * Request::withAttribute('domain', $ctx) — never bound as a container
 * singleton, so it is OpenSwoole/coroutine-safe by construction.
 *
 * Modules that need it should read it from the request:
 *   $ctx = $request->attribute('domain');
 *
 * @phpstan-type FeatureList list<string|array<string, mixed>>
 */
final readonly class DomainContext
{
    public const PLATFORM = '__platform__';

    /**
     * @param FeatureList $features
     */
    public function __construct(
        /** Registered project name from projects.json, or '__platform__' for no-project match. */
        public string $name,

        /** Absolute path to the project directory, e.g. /abs/path/projects/admin. */
        public string $projectPath,

        /** Resolved face (admin / api / project / public). */
        public DomainType $type,

        /** Clean lowercased hostname (no port, no trailing dot) that triggered this context. */
        public string $host,

        /**
         * Feature flags from {projectPath}/proj.json "features" key.
         * Each entry is either a plain string id or an array describing it.
         *
         * @var FeatureList
         */
        public array $features = [],
    ) {
    }

    public function isPlatformOnly(): bool
    {
        return $this->name === self::PLATFORM;
    }

    public function isAdmin(): bool
    {
        return $this->type === DomainType::Admin;
    }

    public function isApi(): bool
    {
        return $this->type === DomainType::Api;
    }

    public function isProject(): bool
    {
        return $this->type === DomainType::Project;
    }

    public function isPublic(): bool
    {
        return $this->type === DomainType::Public;
    }
}
