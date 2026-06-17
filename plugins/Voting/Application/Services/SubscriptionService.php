<?php

declare(strict_types=1);

namespace Plugins\Voting\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Voting\API\Contracts\SubscriptionServiceContract;
use Plugins\Voting\API\DTOs\SubscribeDTO;
use Plugins\Voting\API\DTOs\SubscriptionDTO;
use Plugins\Voting\API\IntegrationEvents\SubscriptionUpgradedIntegrationEvent;
use Plugins\Voting\Domain\Entities\Transaction;
use Plugins\Voting\Domain\Entities\UserSubscription;
use Plugins\Voting\Domain\Events\SubscriptionUpgradedDomainEvent;
use Plugins\Voting\Domain\ValueObjects\EditionId;
use Plugins\Voting\Domain\ValueObjects\TransactionType;
use Plugins\Voting\Infrastructure\Gateways\PaymentGatewayContract;
use Plugins\Voting\Infrastructure\Persistence\EditionRepository;
use Plugins\Voting\Infrastructure\Persistence\EditionSettingsRepository;
use Plugins\Voting\Infrastructure\Persistence\TransactionRepository;
use Plugins\Voting\Infrastructure\Persistence\UserSubscriptionRepository;

final class SubscriptionService implements SubscriptionServiceContract
{
    public function __construct(
        private readonly UserSubscriptionRepository $subscriptionRepository,
        private readonly EditionSettingsRepository  $settingsRepository,
        private readonly EditionRepository         $editionRepository,
        private readonly TransactionRepository     $transactionRepository,
        private readonly PaymentGatewayContract    $paymentGateway,
        private readonly TransactionManager        $transaction,
        private readonly DomainEventCollector      $collector,
        private readonly EventBus                  $eventBus,
        private readonly Identity                  $identity,
    ) {}

    public function get(string $editionId): SubscriptionDTO
    {
        if ($this->identity->isGuest()) {
            throw new ServiceException('voting.subscription.unauthenticated', layer: 'service.voting.subscription');
        }

        $sub = $this->subscriptionRepository->findByUserAndEdition($this->identity->userId, $editionId);

        if ($sub === null) {
            $sub = UserSubscription::free($this->identity->userId, EditionId::from($editionId));
        }

        return SubscriptionDTO::fromEntity($sub);
    }

    public function subscribe(SubscribeDTO $dto): SubscriptionDTO
    {
        if ($this->identity->isGuest()) {
            throw new ServiceException('voting.subscription.unauthenticated', layer: 'service.voting.subscription');
        }

        $edition = $this->editionRepository->find($dto->editionId);
        if ($edition === null) {
            throw new ServiceException(
                'voting.subscription.edition_not_found',
                layer:   'service.voting.subscription',
                context: ['edition_id' => $dto->editionId],
            );
        }

        $settings = $this->settingsRepository->findOrCreate($edition->id());

        if (!$settings->subscriptionEnabled()) {
            throw new ServiceException('voting.subscription.disabled_for_edition', layer: 'service.voting.subscription');
        }

        $current = $this->subscriptionRepository->findByUserAndEdition($this->identity->userId, $dto->editionId);

        if ($current !== null && !$dto->level->isHigherThan($current->level())) {
            throw new ServiceException(
                'voting.subscription.level_not_higher',
                layer:   'service.voting.subscription',
                context: ['current' => $current->level()->value, 'requested' => $dto->level->value],
            );
        }

        $price = $settings->priceForLevel($dto->level);
        $txRef = 'HKM-SUB-' . bin2hex(random_bytes(8));

        $meta = [
            'consumer_id' => $this->identity->userId,
            'edition_id'  => $dto->editionId,
            'level'       => $dto->level->value,
            'level_key'   => $dto->level->key(),
            'amount'      => $price,
            'redirect'    => $dto->redirectUrl,
        ];

        $paymentLink = $this->paymentGateway->initiatePayment(
            txRef:         $txRef,
            amount:        $price,
            currency:      $settings->currency(),
            customerEmail: $this->identity->userId,
            meta:          $meta,
            redirectUrl:   $dto->redirectUrl,
            title:         'AfricaVoting',
            description:   'Vote Subscription — ' . ucfirst($dto->level->value),
        );

        $this->transaction->begin();
        try {
            $tx = Transaction::create(
                txRef:    $txRef,
                userId:   $this->identity->userId,
                amount:   $price,
                currency: $settings->currency(),
                type:     TransactionType::Subscription,
                metadata: $meta,
            );

            $this->transactionRepository->save($tx);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw new ServiceException(
                'voting.subscription.initiate_failed',
                layer:    'service.voting.subscription',
                previous: $e,
            );
        }

        // $current may be null (first-time subscriber); confirm() handles upgrade on payment success
        return SubscriptionDTO::fromEntity(
            $current ?? UserSubscription::free($this->identity->userId, $edition->id()),
            $paymentLink,
        );
    }

    public function confirm(string $txRef): SubscriptionDTO
    {
        if ($txRef === '') {
            throw new ServiceException('voting.subscription.invalid_tx_ref', layer: 'service.voting.subscription');
        }

        $verification = $this->paymentGateway->verifyPayment($txRef);

        $tx = $this->transactionRepository->find($txRef);
        if ($tx === null) {
            throw new ServiceException(
                'voting.subscription.transaction_not_found',
                layer:   'service.voting.subscription',
                context: ['tx_ref' => $txRef],
            );
        }

        if (!$verification['succeeded']) {
            $this->transaction->begin();
            try { $tx->fail(); $this->transactionRepository->save($tx); $this->transaction->commit(); }
            catch (\Throwable) { $this->transaction->rollback(); }
            throw new ServiceException('voting.subscription.payment_failed', layer: 'service.voting.subscription');
        }

        $meta      = $verification['meta'];
        $userId    = (string) ($meta['consumer_id'] ?? $tx->metadata()['consumer_id'] ?? '');
        $editionId = (string) ($meta['edition_id']  ?? $tx->metadata()['edition_id']  ?? '');
        $levelVal  = (string) ($meta['level']       ?? $tx->metadata()['level']       ?? 'free');

        $settings  = $this->settingsRepository->findOrCreate(EditionId::from($editionId));
        $newLevel  = \Plugins\Voting\Domain\ValueObjects\SubscriptionLevel::from($levelVal);
        $dailyVotes = $settings->dailyVotesForLevel($newLevel);

        $sub = $this->subscriptionRepository->findByUserAndEdition($userId, $editionId)
            ?? UserSubscription::free($userId, EditionId::from($editionId));

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $sub->upgrade($newLevel, $dailyVotes, $txRef);
            foreach ($sub->releaseEvents() as $event) {
                $this->collector->collect($event);
            }

            $tx->complete();

            $this->subscriptionRepository->save($sub);
            $this->transactionRepository->save($tx);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw new ServiceException(
                'voting.subscription.confirm_failed',
                layer:    'service.voting.subscription',
                previous: $e,
            );
        }

        foreach ($this->collector->release() as $event) {
            if ($event instanceof SubscriptionUpgradedDomainEvent) {
                $this->eventBus->dispatch(new SubscriptionUpgradedIntegrationEvent(
                    userId:    $event->userId,
                    editionId: $event->editionId->value(),
                    fromLevel: $event->fromLevel->value,
                    toLevel:   $event->toLevel->value,
                    occurredAt: $event->occurredAt->format(\DateTimeInterface::RFC3339),
                ));
            }
        }

        return SubscriptionDTO::fromEntity($sub);
    }
}
