<?php

declare(strict_types=1);

namespace Plugins\Voting\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Voting\API\Contracts\BoostingServiceContract;
use Plugins\Voting\API\DTOs\BoostDTO;
use Plugins\Voting\API\DTOs\InitiateBoostDTO;
use Plugins\Voting\API\IntegrationEvents\BoostConfirmedIntegrationEvent;
use Plugins\Voting\Domain\Entities\Boost;
use Plugins\Voting\Domain\Entities\Transaction;
use Plugins\Voting\Domain\Events\BoostConfirmedDomainEvent;
use Plugins\Voting\Domain\ValueObjects\BoostType;
use Plugins\Voting\Domain\ValueObjects\TransactionType;
use Plugins\Voting\Infrastructure\Gateways\PaymentGatewayContract;
use Plugins\Voting\Infrastructure\Persistence\BoostRepository;
use Plugins\Voting\Infrastructure\Persistence\ContestantRepository;
use Plugins\Voting\Infrastructure\Persistence\EditionSettingsRepository;
use Plugins\Voting\Infrastructure\Persistence\TransactionRepository;

final class BoostingService implements BoostingServiceContract
{
    public function __construct(
        private readonly ContestantRepository      $contestantRepository,
        private readonly EditionSettingsRepository $settingsRepository,
        private readonly BoostRepository           $boostRepository,
        private readonly TransactionRepository     $transactionRepository,
        private readonly PaymentGatewayContract    $paymentGateway,
        private readonly TransactionManager        $transaction,
        private readonly DomainEventCollector      $collector,
        private readonly EventBus                  $eventBus,
        private readonly Identity                  $identity,
        private readonly string                    $appLogoUrl,
    ) {}

    public function initiate(InitiateBoostDTO $dto): BoostDTO
    {
        if ($this->identity->isGuest()) {
            throw new ServiceException('voting.boost.unauthenticated', layer: 'service.voting.boosting');
        }

        $contestant = $this->contestantRepository->find($dto->contestantId);
        if ($contestant === null) {
            throw new ServiceException(
                'voting.boost.contestant_not_found',
                layer:   'service.voting.boosting',
                context: ['contestant_id' => $dto->contestantId],
            );
        }

        $settings = $this->settingsRepository->findOrCreate($contestant->editionId());

        if (!$settings->boostingEnabled()) {
            throw new ServiceException('voting.boost.disabled_for_edition', layer: 'service.voting.boosting');
        }

        $cost  = $settings->calculateBoostCost($dto->votes);
        $txRef = 'HKM-' . bin2hex(random_bytes(8));

        $meta = [
            'consumer_id'   => $this->identity->userId,
            'contestant_id' => $dto->contestantId,
            'edition_id'    => $contestant->editionId()->value(),
            'votes'         => $dto->votes,
            'amount'        => $cost,
            'redirect'      => $dto->redirectUrl,
        ];

        $paymentLink = $this->paymentGateway->initiatePayment(
            txRef:         $txRef,
            amount:        $cost,
            currency:      $settings->currency(),
            customerEmail: $this->identity->userId,
            meta:          $meta,
            redirectUrl:   $dto->redirectUrl,
            title:         'AfricaVoting',
            description:   'Participant Boost',
        );

        $this->transaction->begin();
        try {
            $tx = Transaction::create(
                txRef:    $txRef,
                userId:   $this->identity->userId,
                amount:   $cost,
                currency: $settings->currency(),
                type:     TransactionType::Boosting,
                metadata: $meta,
            );

            $boost = Boost::initiate(
                userId:        $this->identity->userId,
                contestantId:  $contestant->id(),
                editionId:     $contestant->editionId(),
                boostAmount:   $cost,
                boostedVotes:  $dto->votes,
                boostType:     BoostType::Regular,
                transactionId: $txRef,
            );

            $this->transactionRepository->save($tx);
            $this->boostRepository->save($boost);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw new ServiceException(
                'voting.boost.initiate_failed',
                layer:    'service.voting.boosting',
                previous: $e,
            );
        }

        return BoostDTO::fromEntity($boost, $paymentLink);
    }

    public function confirm(string $txRef): BoostDTO
    {
        if ($txRef === '') {
            throw new ServiceException('voting.boost.invalid_tx_ref', layer: 'service.voting.boosting');
        }

        $verification = $this->paymentGateway->verifyPayment($txRef);

        $tx = $this->transactionRepository->find($txRef);
        if ($tx === null) {
            throw new ServiceException(
                'voting.boost.transaction_not_found',
                layer:   'service.voting.boosting',
                context: ['tx_ref' => $txRef],
            );
        }

        $boost = $this->boostRepository->findByTransactionId($txRef);
        if ($boost === null) {
            throw new ServiceException(
                'voting.boost.boost_not_found',
                layer:   'service.voting.boosting',
                context: ['tx_ref' => $txRef],
            );
        }

        if (!$verification['succeeded']) {
            $this->transaction->begin();
            try {
                $tx->fail();
                $this->transactionRepository->save($tx);
                $this->transaction->commit();
            } catch (\Throwable $e) {
                $this->transaction->rollback();
            }
            throw new ServiceException('voting.boost.payment_failed', layer: 'service.voting.boosting');
        }

        $contestant = $this->contestantRepository->find($boost->contestantId()->value());
        if ($contestant === null) {
            throw new ServiceException('voting.boost.contestant_not_found', layer: 'service.voting.boosting');
        }

        $settings = $this->settingsRepository->findOrCreate($boost->editionId());

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $boost->confirm();
            foreach ($boost->releaseEvents() as $event) {
                $this->collector->collect($event);
            }

            $tx->complete();

            // Atomic SQL increments avoid races under high load
            $boostedVotes = $boost->boostedVotes();
            $this->boostRepository->save($boost);
            $this->contestantRepository->atomicIncrementVoteCount($contestant->id()->value(), $boostedVotes);
            $this->contestantRepository->atomicIncrementBoostCount($contestant->id()->value(), $boostedVotes);
            $this->settingsRepository->atomicIncrementVoteCount($boost->editionId()->value(), $boostedVotes);
            $this->transactionRepository->save($tx);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw new ServiceException(
                'voting.boost.confirm_failed',
                layer:    'service.voting.boosting',
                previous: $e,
            );
        }

        foreach ($this->collector->release() as $event) {
            if ($event instanceof BoostConfirmedDomainEvent) {
                $this->eventBus->dispatch(new BoostConfirmedIntegrationEvent(
                    boostId:      $event->boostId,
                    userId:       $event->userId,
                    contestantId: $event->contestantId->value(),
                    editionId:    $event->editionId->value(),
                    boostedVotes: $event->boostedVotes,
                    occurredAt:   $event->occurredAt->format(\DateTimeInterface::RFC3339),
                ));
            }
        }

        return BoostDTO::fromEntity($boost);
    }
}
