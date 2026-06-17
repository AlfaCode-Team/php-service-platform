<?php

declare(strict_types=1);

namespace Plugins\Voting;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfaCode\PulseEngine\Config\VotingConfig;
use AlfaCode\PulseEngine\Contract\CacheInterface;
use AlfaCode\PulseEngine\Security\IntegrityGuard;
use AlfaCode\PulseEngine\Security\RateLimiter;
use AlfaCode\PulseEngine\Service\PricingEngine;
use Plugins\Voting\API\Contracts\BoostingServiceContract;
use Plugins\Voting\API\Contracts\EditionServiceContract;
use Plugins\Voting\API\Contracts\EditionSettingsServiceContract;
use Plugins\Voting\API\Contracts\SubscriptionServiceContract;
use Plugins\Voting\API\Contracts\VotingServiceContract;
use Plugins\Voting\Application\Services\BoostingService;
use Plugins\Voting\Application\Services\EditionService;
use Plugins\Voting\Application\Services\EditionSettingsService;
use Plugins\Voting\Application\Services\SubscriptionService;
use Plugins\Voting\Application\Services\VotingService;
use Plugins\Voting\Infrastructure\Gateways\FlutterwavePaymentGateway;
use Plugins\Voting\Infrastructure\Gateways\PaymentGatewayContract;
use Plugins\Voting\Infrastructure\Engine\PulseCacheAdapter;
use Plugins\Voting\Infrastructure\Engine\PulseConfigFactory;
use Plugins\Voting\Infrastructure\Persistence\BoostRepository;
use Plugins\Voting\Infrastructure\Persistence\CategoryMetaRepository;
use Plugins\Voting\Infrastructure\Persistence\ContestantMetaRepository;
use Plugins\Voting\Infrastructure\Persistence\ContestantRepository;
use Plugins\Voting\Infrastructure\Persistence\EditionMetaRepository;
use Plugins\Voting\Infrastructure\Persistence\EditionRepository;
use Plugins\Voting\Infrastructure\Persistence\EditionSettingsRepository;
use Plugins\Voting\Infrastructure\Persistence\TransactionRepository;
use Plugins\Voting\Infrastructure\Persistence\UserSubscriptionRepository;
use Plugins\Voting\Infrastructure\Persistence\VoteRepository;

final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'voting.management';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [DatabasePort::class, CachePort::class];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [
            VotingServiceContract::class,
            EditionServiceContract::class,
            EditionSettingsServiceContract::class,
            BoostingServiceContract::class,
            SubscriptionServiceContract::class,
        ];
    }

    public function register(ModuleContainer $container): void
    {
        $cooldownHours = (int) (env('VOTING_COOLDOWN_HOURS') ?: 24);
        $fwSecretKey   = (string) (env('VOTING_FLUTTERWAVE_SECRET_KEY') ?: '');
        $fwPublicKey   = (string) (env('VOTING_FLUTTERWAVE_PUBLIC_KEY') ?: '');
        $appLogoUrl    = (string) (env('VOTING_APP_LOGO_URL') ?: '');

        // ── Repositories (internal) ───────────────────────────────────────────

        $container->bindInternal(EditionRepository::class, static fn(ModuleContainer $c) =>
            new EditionRepository($c->make(DatabasePort::class), $c->make(Identity::class))
        );

        $container->bindInternal(ContestantRepository::class, static fn(ModuleContainer $c) =>
            new ContestantRepository($c->make(DatabasePort::class), $c->make(Identity::class))
        );

        $container->bindInternal(VoteRepository::class, static fn(ModuleContainer $c) =>
            new VoteRepository($c->make(DatabasePort::class))
        );

        $container->bindInternal(EditionSettingsRepository::class, static fn(ModuleContainer $c) =>
            new EditionSettingsRepository($c->make(DatabasePort::class))
        );

        $container->bindInternal(UserSubscriptionRepository::class, static fn(ModuleContainer $c) =>
            new UserSubscriptionRepository($c->make(DatabasePort::class))
        );

        $container->bindInternal(BoostRepository::class, static fn(ModuleContainer $c) =>
            new BoostRepository($c->make(DatabasePort::class), $c->make(Identity::class))
        );

        $container->bindInternal(TransactionRepository::class, static fn(ModuleContainer $c) =>
            new TransactionRepository($c->make(DatabasePort::class))
        );

        $container->bindInternal(EditionMetaRepository::class, static fn(ModuleContainer $c) =>
            new EditionMetaRepository($c->make(DatabasePort::class))
        );

        $container->bindInternal(ContestantMetaRepository::class, static fn(ModuleContainer $c) =>
            new ContestantMetaRepository($c->make(DatabasePort::class))
        );

        $container->bindInternal(CategoryMetaRepository::class, static fn(ModuleContainer $c) =>
            new CategoryMetaRepository($c->make(DatabasePort::class))
        );

        // ── pulse-engine bridge (the Voting plugin depends on pulse-engine) ──
        // Cross-cutting voting compute (rate limiting, pricing, integrity) is
        // delegated to the standalone pulse-engine, driven through adapters that
        // map pulse-engine's contracts onto the kernel ports.

        $container->bindInternal(VotingConfig::class, static fn() =>
            PulseConfigFactory::fromEnvironment()
        );

        $container->bindInternal(CacheInterface::class, static fn(ModuleContainer $c) =>
            new PulseCacheAdapter($c->make(CachePort::class))
        );

        $container->bindInternal(RateLimiter::class, static fn(ModuleContainer $c) =>
            new RateLimiter($c->make(CacheInterface::class), $c->make(VotingConfig::class))
        );

        $container->bindInternal(PricingEngine::class, static fn(ModuleContainer $c) =>
            new PricingEngine($c->make(VotingConfig::class))
        );

        $container->bindInternal(IntegrityGuard::class, static fn(ModuleContainer $c) =>
            new IntegrityGuard($c->make(VotingConfig::class))
        );

        // ── Gateway (internal) ────────────────────────────────────────────────

        $container->bindInternal(PaymentGatewayContract::class, static fn() =>
            new FlutterwavePaymentGateway(
                secretKey: $fwSecretKey,
                publicKey: $fwPublicKey,
                logoUrl:   $appLogoUrl,
            )
        );

        // ── Public services ───────────────────────────────────────────────────

        $container->bind(VotingServiceContract::class, static fn(ModuleContainer $c) =>
            new VotingService(
                contestantRepository:  $c->make(ContestantRepository::class),
                editionRepository:     $c->make(EditionRepository::class),
                voteRepository:        $c->make(VoteRepository::class),
                settingsRepository:    $c->make(EditionSettingsRepository::class),
                subscriptionRepository: $c->make(UserSubscriptionRepository::class),
                transaction:           $c->make(TransactionManager::class),
                collector:             $c->make(DomainEventCollector::class),
                eventBus:              $c->make(EventBus::class),
                rateLimiter:           $c->make(RateLimiter::class),
                identity:              $c->make(Identity::class),
                cooldownHours:         $cooldownHours,
            )
        );

        $container->bind(EditionServiceContract::class, static fn(ModuleContainer $c) =>
            new EditionService(
                editionRepository:    $c->make(EditionRepository::class),
                contestantRepository: $c->make(ContestantRepository::class),
                transaction:          $c->make(TransactionManager::class),
                collector:            $c->make(DomainEventCollector::class),
                eventBus:             $c->make(EventBus::class),
                identity:             $c->make(Identity::class),
            )
        );

        $container->bind(EditionSettingsServiceContract::class, static fn(ModuleContainer $c) =>
            new EditionSettingsService(
                settingsRepository: $c->make(EditionSettingsRepository::class),
                editionRepository:  $c->make(EditionRepository::class),
                transaction:        $c->make(TransactionManager::class),
                identity:           $c->make(Identity::class),
            )
        );

        $container->bind(BoostingServiceContract::class, static fn(ModuleContainer $c) =>
            new BoostingService(
                contestantRepository: $c->make(ContestantRepository::class),
                settingsRepository:   $c->make(EditionSettingsRepository::class),
                boostRepository:      $c->make(BoostRepository::class),
                transactionRepository: $c->make(TransactionRepository::class),
                paymentGateway:       $c->make(PaymentGatewayContract::class),
                transaction:          $c->make(TransactionManager::class),
                collector:            $c->make(DomainEventCollector::class),
                eventBus:             $c->make(EventBus::class),
                identity:             $c->make(Identity::class),
                appLogoUrl:           $appLogoUrl,
            )
        );

        $container->bind(SubscriptionServiceContract::class, static fn(ModuleContainer $c) =>
            new SubscriptionService(
                subscriptionRepository: $c->make(UserSubscriptionRepository::class),
                settingsRepository:     $c->make(EditionSettingsRepository::class),
                editionRepository:      $c->make(EditionRepository::class),
                transactionRepository:  $c->make(TransactionRepository::class),
                paymentGateway:         $c->make(PaymentGatewayContract::class),
                transaction:            $c->make(TransactionManager::class),
                collector:              $c->make(DomainEventCollector::class),
                eventBus:               $c->make(EventBus::class),
                identity:               $c->make(Identity::class),
            )
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
