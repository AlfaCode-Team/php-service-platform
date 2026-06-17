<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Error\Notifiers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Error\Contracts\NotifierContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorContext;

/**
 * FileNotifier — the guaranteed fallback notifier.
 *
 * Appends one JSON line per error to a log file. Always safe to run: it never
 * throws, creating the directory on demand and degrading silently if the
 * filesystem is unavailable (there is nowhere left to escalate to).
 *
 * Optional daily rotation: when enabled, the date is injected before the file
 * extension (errors.log → errors-2026-06-04.log) so log files stay bounded and
 * are trivial to archive/prune. Rotation is computed per-write from the current
 * date, so it is safe under long-lived OpenSwoole workers (no cached handle,
 * no mutable per-request state).
 */
final class FileNotifier implements NotifierContract
{
    public function __construct(
        private readonly string $path,
        private readonly bool $rotateDaily = false,
    ) {}

    public function name(): string
    {
        return 'file';
    }

    public function notify(ErrorContext $context): void
    {
        try {
            $path = $this->resolvePath();
            $dir  = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $line = json_encode($context->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Last line of defence — nothing left to escalate to.
        }
    }

    private function resolvePath(): string
    {
        if (!$this->rotateDaily) {
            return $this->path;
        }

        $date = (new \DateTimeImmutable())->format('Y-m-d');
        $ext  = pathinfo($this->path, PATHINFO_EXTENSION);

        if ($ext === '') {
            return $this->path . '-' . $date;
        }

        return substr($this->path, 0, -(strlen($ext) + 1)) . '-' . $date . '.' . $ext;
    }
}
