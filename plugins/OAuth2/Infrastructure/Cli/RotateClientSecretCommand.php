<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\OAuth2\Application\Ports\ClientStore;

/** oauth:client:rotate — issue a new secret for a confidential client. */
final class RotateClientSecretCommand extends AbstractCommand
{
    public function __construct(
        private readonly ClientStore $clients,
        private readonly HashingPort $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'oauth:client:rotate';
        $this->description = 'Rotate a confidential OAuth2 client secret';

        $this->addOption('client', 'c', 'Client id', acceptsValue: true);
    }

    protected function handle(): int
    {
        $id = trim((string) $this->option('client'));
        if ($id === '') {
            $this->error('Provide --client <id>.');
            return self::FAILURE;
        }

        $secret = bin2hex(random_bytes(32));
        if (!$this->clients->updateSecret($id, $this->hasher->make($secret))) {
            $this->error("No confidential client found for id: {$id}");
            return self::FAILURE;
        }

        $this->success('Secret rotated. The previous secret is now invalid.');
        $this->info('client_id     : ' . $id);
        $this->info('client_secret : ' . $secret . '   (shown once — store it now)');

        return self::SUCCESS;
    }
}
