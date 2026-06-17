<?php

declare(strict_types=1);

namespace Plugins\Commands\Infrastructure\Gateways;

use AlfacodeTeam\PhpIoCli\Depends\Shell;
use AlfacodeTeam\PhpIoCli\Depends\ShellResult;
use Plugins\Commands\Exceptions\ServiceException;

/**
 * ShellGateway — wraps Shell operations for git commands and system calls.
 * Only this gateway translates Shell exceptions to service exceptions.
 *
 * Shell is a static utility (private constructor), so it is used statically
 * rather than injected.
 */
final class ShellGateway
{
    /**
     * Execute a shell command and return the result.
     *
     * @throws ServiceException on failure
     */
    public function execute(string $command, ?string $context = null): ShellResult
    {
        try {
            return Shell::run($command);
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Shell command failed: {$command}\n{$e->getMessage()}",
            );
        }
    }

    /**
     * Run a git command.
     */
    public function git(string $args): ShellResult
    {
        return $this->execute("git {$args}", context: 'git');
    }

    /**
     * Check if git is available.
     */
    public function gitAvailable(): bool
    {
        try {
            $result = Shell::run('git --version');
            return $result->ok();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if composer is available.
     */
    public function composerAvailable(): bool
    {
        try {
            $result = Shell::run('composer --version');
            return $result->ok();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if a directory exists.
     */
    public function directoryExists(string $path): bool
    {
        return is_dir($path);
    }

    /**
     * Check if a file exists.
     */
    public function fileExists(string $path): bool
    {
        return is_file($path);
    }

    /**
     * Ensure a directory exists (create if missing).
     *
     * @throws ServiceException
     */
    public function ensureDirectory(string $path): void
    {
        if ($this->directoryExists($path)) {
            return;
        }

        if (!@mkdir($path, 0755, true)) {
            throw ServiceException::moduleAddFailed("Could not create directory: {$path}");
        }
    }

    /**
     * Write content to a file.
     *
     * @throws ServiceException
     */
    public function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!$this->directoryExists($dir)) {
            $this->ensureDirectory($dir);
        }

        if (file_put_contents($path, $content) === false) {
            throw ServiceException::moduleAddFailed("Could not write file: {$path}");
        }
    }

    /**
     * Read file contents.
     *
     * @throws ServiceException
     */
    public function readFile(string $path): string
    {
        if (!$this->fileExists($path)) {
            throw ServiceException::moduleAddFailed("File not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw ServiceException::moduleAddFailed("Could not read file: {$path}");
        }

        return $content;
    }
}
