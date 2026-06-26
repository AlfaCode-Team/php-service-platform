<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader};

/**
 * Reads every module's config[] declarations from module.json.
 * Verifies each required env var exists and satisfies its type constraint.
 * Reports ALL problems in one exception - not just the first.
 */
final class ValidateConfigStage implements BootStageContract
{
    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {
    }

    public function run(): void
    {
        $missing = $invalid = [];

        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            $moduleName = $manifest['name'] ?? $moduleClass;

            foreach ($manifest['config'] ?? [] as $key => $spec) {
                // config[] entries are either a bare "VAR_NAME" string, or an
                // object form { "key": "VAR_NAME", "type": ..., "required": ... }.
                // The object form may appear at a numeric index (list style) or
                // be keyed by the var name (map style).
                $varName = is_array($spec)
                    ? (string) ($spec['key'] ?? $key)
                    : (is_int($key) ? (string) $spec : (string) $key);
                $type = is_array($spec) ? ($spec['type'] ?? 'string') : 'string';
                $required = is_array($spec) ? ($spec['required'] ?? true) : true;

                if (function_exists('env')) {
                    $value = env($varName, getenv($varName) ?: null);
                } else
                    $value = $_ENV[$varName] ?? (getenv($varName) ?: null);

                if ($value === null || $value === '') {
                    if ($required) {
                        $missing[] = "{$varName} (required by {$moduleName})";
                    }
                    continue;
                }

                if (!$this->isValidType((string) $value, $type)) {
                    $invalid[] = "{$varName} must be {$type} (declared by {$moduleName})";
                }
            }
        }

        if ($missing !== [] || $invalid !== []) {
            throw new BootException(
                "Configuration invalid.\n"
                . ($missing !== [] ? "  Missing: " . implode(', ', $missing) . "\n" : '')
                . ($invalid !== [] ? "  Invalid: " . implode(', ', $invalid) . "\n" : '')
            );
        }
    }

    private function isValidType(string $value, string $type): bool
    {
        return match ($type) {
            'integer', 'int' => ctype_digit(ltrim($value, '-')),
            'float' => is_numeric($value),
            'bool', 'boolean' => in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no'], true),
            default => true,
        };
    }
}
