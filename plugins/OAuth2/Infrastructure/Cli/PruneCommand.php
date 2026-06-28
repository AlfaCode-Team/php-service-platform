<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\OAuth2\Application\Ports\AuthCodeStore;
use Plugins\OAuth2\Application\Ports\DeviceCodeStore;
use Plugins\OAuth2\Application\Ports\RefreshTokenStore;

/**
 * oauth:prune — delete expired authorization codes, refresh tokens and device
 * codes. Run on a schedule (cron) or via `--watch` for a supervised loop.
 */
final class PruneCommand extends AbstractCommand
{
    public function __construct(
        private readonly AuthCodeStore $codes,
        private readonly RefreshTokenStore $refreshTokens,
        private readonly DeviceCodeStore $devices,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'oauth:prune';
        $this->description = 'Delete expired OAuth2 authorization codes, refresh tokens and device codes';

        $this->addOption('watch', '', 'Run forever, pruning every N seconds', acceptsValue: true, default: '');
    }

    protected function handle(): int
    {
        $watch = (int) $this->option('watch');
        if ($watch <= 0) {
            return $this->prune();
        }

        $interval = max(60, $watch);
        $this->info("Watching: pruning every {$interval}s. Ctrl-C to stop.");
        while (true) {
            $this->prune();
            sleep($interval);
        }
    }

    private function prune(): int
    {
        $codes   = $this->codes->deleteExpired();
        $tokens  = $this->refreshTokens->deleteExpired();
        $devices = $this->devices->deleteExpired();

        $this->info("Pruned: {$codes} auth code(s), {$tokens} refresh token(s), {$devices} device code(s).");

        return self::SUCCESS;
    }
}
