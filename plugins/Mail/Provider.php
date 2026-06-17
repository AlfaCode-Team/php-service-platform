<?php

declare(strict_types=1);

namespace Plugins\Mail;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;
use Plugins\Mail\Infrastructure\SmtpMailer;
use Plugins\Mail\Infrastructure\SmtpTransport;

/**
 * Mail plugin — SMTP adapter for the kernel MailPort.
 *
 * Binds MailPort to an SmtpMailer built from env, but ONLY when SMTP_HOST is
 * set, so projects without mail configured boot unaffected (the kernel/project
 * may bind its own MailPort otherwise).
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'mail.smtp';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [MailPort::class];
    }

    public function register(ModuleContainer $container): void
    {
        $host = env('SMTP_HOST') ?: '';
        if ($host === '' || $container->has(MailPort::class)) {
            return; // not configured, or a project already provided MailPort
        }

        $container->bind(MailPort::class, static function () use ($host) {
            $transport = new SmtpTransport(
                host:       $host,
                port:       (int) (env('SMTP_PORT') ?: 587),
                username:   env('SMTP_USERNAME') ?: null,
                password:   env('SMTP_PASSWORD') ?: null,
                encryption: env('SMTP_ENCRYPTION') ?: 'tls',
            );

            return new SmtpMailer(
                transport: $transport,
                fromEmail: env('MAIL_FROM_ADDRESS') ?: 'no-reply@localhost',
                fromName:  env('MAIL_FROM_NAME') ?: '',
                viewsPath: env('MAIL_VIEWS_PATH') ?: '',
            );
        });
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
