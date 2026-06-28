<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\OAuth2\Application\Ports\ClientStore;

/** oauth:client:list — list registered OAuth2 clients. */
final class ListClientsCommand extends AbstractCommand
{
    public function __construct(private readonly ClientStore $clients)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'oauth:client:list';
        $this->description = 'List registered OAuth2 clients';
    }

    protected function handle(): int
    {
        $clients = $this->clients->all();
        if ($clients === []) {
            $this->info('No OAuth2 clients registered.');
            return self::SUCCESS;
        }

        foreach ($clients as $c) {
            $type = $c->confidential ? 'confidential' : 'public';
            $flag = $c->revoked ? ' [REVOKED]' : '';
            $this->info(sprintf(
                '%s  %-20s  %-12s  grants=%s%s',
                $c->id,
                $c->name,
                $type,
                implode(',', $c->grantTypes) ?: '-',
                $flag,
            ));
        }

        return self::SUCCESS;
    }
}
