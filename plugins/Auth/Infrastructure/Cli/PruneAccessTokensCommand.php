<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Auth\Infrastructure\Persistence\PersonalAccessTokenRepository;

/**
 * auth:tokens:prune — delete expired personal access tokens.
 *
 * PATs with a past `expires_at` are already rejected at authentication time, but
 * the rows linger. Run this on a schedule (cron / the platform worker) to keep
 * the table bounded.
 *
 *   hkm auth:tokens:prune              # delete every expired token, report the count
 *   hkm auth:tokens:prune --dry        # report how many WOULD be deleted, change nothing
 *   hkm auth:tokens:prune --watch=3600 # supervised loop: prune every hour (no cron needed)
 */
final class PruneAccessTokensCommand extends AbstractCommand
{
    public function __construct(
        private readonly PersonalAccessTokenRepository $tokens,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'auth:tokens:prune';
        $this->description = 'Delete expired personal access tokens from the control-plane table';

        $this->addOption('dry', '', 'Report the count without deleting anything');
        $this->addOption('watch', '', 'Run forever, pruning every N seconds (supervised loop)', acceptsValue: true, default: '');
    }

    protected function handle(): int
    {
        $watch = (int) $this->option('watch');
        if ($watch <= 0) {
            return $this->prune();
        }

        // Supervised loop for environments without cron (containers/systemd).
        // A process supervisor restarts it if it exits; min 60s guards against a
        // hot loop.
        $interval = max(60, $watch);
        $this->info("Watching: pruning expired access tokens every {$interval}s. Ctrl-C to stop.");
        while (true) {
            $this->prune();
            sleep($interval);
        }
    }

    private function prune(): int
    {
        if ($this->hasOption('dry')) {
            $count = $this->tokens->countExpired();
            $this->info("{$count} expired access token(s) would be pruned (dry run — nothing deleted).");

            return self::SUCCESS;
        }

        $deleted = $this->tokens->deleteExpired();
        $this->info("Pruned {$deleted} expired access token(s).");

        return self::SUCCESS;
    }
}
