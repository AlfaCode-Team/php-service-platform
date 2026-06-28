<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\OAuth2\Application\Ports\ClientStore;

/** oauth:client:revoke — revoke an OAuth2 client (stops all its tokens). */
final class RevokeClientCommand extends AbstractCommand
{
    public function __construct(private readonly ClientStore $clients)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'oauth:client:revoke';
        $this->description = 'Revoke an OAuth2 client by id';

        $this->addOption('client', 'c', 'Client id to revoke', acceptsValue: true);
    }

    protected function handle(): int
    {
        $id = trim((string) $this->option('client'));
        if ($id === '') {
            $this->error('Provide --client <id>.');
            return self::FAILURE;
        }

        if (!$this->clients->revoke($id)) {
            $this->error("Client not found: {$id}");
            return self::FAILURE;
        }

        $this->success("Client {$id} revoked.");
        return self::SUCCESS;
    }
}
