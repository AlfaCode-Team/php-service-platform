<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot;

/**
 * ManifestReader — loads and caches a module's module.json.
 *
 * Reading from module.json (the single source of truth) instead of
 * instantiating the Provider keeps boot side-effect free and means Providers
 * are never required to have a parameterless constructor.
 */
final class ManifestReader
{
    /** @var array<class-string, array<string, mixed>> */
    private array $cache = [];

    /**
     * @param class-string $moduleClass
     * @return array<string, mixed>
     * @throws BootException when the file is missing or invalid
     */
    public function read(string $moduleClass): array
    {
        if (isset($this->cache[$moduleClass])) {
            return $this->cache[$moduleClass];
        }

        $ref  = new \ReflectionClass($moduleClass);
        $file = $ref->getFileName();
        if ($file === false) {
            throw new BootException("Cannot locate source file for [{$moduleClass}].");
        }

        $path = dirname($file) . '/module.json';
        if (!is_file($path)) {
            throw new BootException("module.json not found for [{$moduleClass}] — expected at {$path}");
        }

        $raw     = file_get_contents($path);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new BootException("module.json is invalid JSON for [{$moduleClass}] at {$path}");
        }

        return $this->cache[$moduleClass] = $decoded;
    }
}
