<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

/**
 * Shared base for GDA scaffolding commands.
 *
 * Provides studly/snake helpers and a guarded file writer so generators never
 * clobber existing files unless --force is passed.
 */
abstract class GeneratorCommand extends AbstractCommand
{
    protected function pluginsRoot(): string
    {
        return getcwd() . '/plugins';
    }

    protected function studly(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        return str_replace(' ', '', ucwords($value));
    }

    protected function snake(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value;
        return strtolower(str_replace([' ', '-'], '_', $value));
    }

    protected function kebab(string $value): string
    {
        return str_replace('_', '-', $this->snake($value));
    }

    /**
     * Write $contents to $path, creating directories as needed.
     * Returns false (and reports) if the file exists and --force was not given.
     */
    protected function writeFile(string $path, string $contents, bool $force = false): bool
    {
        if (is_file($path) && !$force) {
            $this->warning('Skipped (exists): ' . $this->relative($path));
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->error('Cannot create directory: ' . $dir);
            return false;
        }

        if (file_put_contents($path, $contents) === false) {
            $this->error('Cannot write file: ' . $this->relative($path));
            return false;
        }

        $this->success('Created: ' . $this->relative($path));
        return true;
    }

    protected function relative(string $path): string
    {
        $cwd = getcwd() . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $cwd) ? substr($path, strlen($cwd)) : $path;
    }
}
