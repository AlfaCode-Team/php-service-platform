<?php

declare(strict_types=1);

namespace Plugins\Commands\Backup;

final class BackupManager
{
    private const BACKUP_DIR = __DIR__ . '/../../../../var/backups';
    private const BACKUP_RETENTION_DAYS = 30;

    public static function createBackup(array $config): BackupFile
    {
        self::ensureBackupDirectory();

        $timestamp = date('YmdHis');
        $filename = "database_backup_{$timestamp}.sql";
        $filepath = self::BACKUP_DIR . '/' . $filename;

        $conn = $config['connections']['default'] ?? null;
        if (!$conn) {
            throw new BackupException('No default database connection configured');
        }

        try {
            self::dumpDatabase($conn, $filepath);

            return new BackupFile(
                path: $filepath,
                filename: $filename,
                timestamp: $timestamp,
                size: filesize($filepath),
                database: $conn['database'],
            );
        } catch (\Throwable $e) {
            // Clean up partial file
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            throw new BackupException("Failed to create backup: {$e->getMessage()}");
        }
    }

    public static function cleanupOldBackups(): int
    {
        if (!is_dir(self::BACKUP_DIR)) {
            return 0;
        }

        $cutoffTime = time() - (self::BACKUP_RETENTION_DAYS * 24 * 60 * 60);
        $deletedCount = 0;

        foreach (glob(self::BACKUP_DIR . '/database_backup_*.sql') as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }

        return $deletedCount;
    }

    public static function listBackups(): array
    {
        if (!is_dir(self::BACKUP_DIR)) {
            return [];
        }

        $backups = [];
        foreach (glob(self::BACKUP_DIR . '/database_backup_*.sql') as $file) {
            $backups[] = new BackupFile(
                path: $file,
                filename: basename($file),
                timestamp: (string) filemtime($file),
                size: filesize($file),
            );
        }

        // Sort by newest first
        usort($backups, fn($a, $b) => $b->timestamp <=> $a->timestamp);

        return $backups;
    }

    private static function ensureBackupDirectory(): void
    {
        if (!is_dir(self::BACKUP_DIR)) {
            if (!mkdir(self::BACKUP_DIR, 0755, true)) {
                throw new BackupException("Cannot create backup directory: " . self::BACKUP_DIR);
            }
        }

        if (!is_writable(self::BACKUP_DIR)) {
            throw new BackupException("Backup directory is not writable: " . self::BACKUP_DIR);
        }
    }

    private static function dumpDatabase(array $conn, string $filepath): void
    {
        $driver = $conn['driver'] ?? 'mysql';

        $command = match ($driver) {
            'mysql'  => self::getMysqlDumpCommand($conn, $filepath),
            'pgsql'  => self::getPostgresDumpCommand($conn, $filepath),
            'sqlite' => self::getSqliteDumpCommand($conn, $filepath),
            default  => throw new BackupException("Backup not supported for driver: {$driver}"),
        };

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new BackupException(
                "Backup command failed (exit code {$returnCode}): " . implode("\n", $output)
            );
        }

        if (!file_exists($filepath)) {
            throw new BackupException("Backup file was not created");
        }
    }

    private static function getMysqlDumpCommand(array $conn, string $filepath): string
    {
        $host = escapeshellarg($conn['host'] ?? 'localhost');
        $user = escapeshellarg($conn['username'] ?? 'root');
        $pass = $conn['password'] ? '-p' . escapeshellarg($conn['password']) : '';
        $database = escapeshellarg($conn['database'] ?? '');

        return "mysqldump -h{$host} -u{$user} {$pass} {$database} > {$filepath}";
    }

    private static function getPostgresDumpCommand(array $conn, string $filepath): string
    {
        $host = escapeshellarg($conn['host'] ?? 'localhost');
        $user = escapeshellarg($conn['username'] ?? 'postgres');
        $database = escapeshellarg($conn['database'] ?? 'postgres');

        $env = "PGPASSWORD=" . escapeshellarg($conn['password'] ?? '');

        return "{$env} pg_dump -h {$host} -U {$user} {$database} > {$filepath}";
    }

    private static function getSqliteDumpCommand(array $conn, string $filepath): string
    {
        $database = escapeshellarg($conn['database'] ?? ':memory:');

        return "sqlite3 {$database} .dump > {$filepath}";
    }
}

final class BackupFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $filename,
        public readonly string $timestamp,
        public readonly int $size,
        public readonly ?string $database = null,
    ) {}

    public function exists(): bool
    {
        return file_exists($this->path);
    }

    public function delete(): bool
    {
        return $this->exists() && unlink($this->path);
    }

    public function getFormattedSize(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

final class BackupException extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
