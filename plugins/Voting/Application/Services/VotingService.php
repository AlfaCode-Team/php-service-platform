<?php

declare(strict_types=1);

namespace Plugins\Voting\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfaCode\PulseEngine\Exception\RateLimitExceededException;
use AlfaCode\PulseEngine\Security\RateLimiter;
use Plugins\Voting\API\Contracts\VotingServiceContract;
use Plugins\Voting\API\DTOs\CastVoteDTO;
use Plugins\Voting\API\DTOs\ContestantDTO;
use Plugins\Voting\API\IntegrationEvents\VoteCastIntegrationEvent;
use Plugins\Voting\Domain\Entities\Contestant;
use Plugins\Voting\Domain\Entities\VoteRecord;
use Plugins\Voting\Domain\Events\VoteCastDomainEvent;
use Plugins\Voting\Domain\Rules\VotingWindowRule;
use Plugins\Voting\Infrastructure\Persistence\ContestantRepository;
use Plugins\Voting\Infrastructure\Persistence\EditionRepository;
use Plugins\Voting\Infrastructure\Persistence\EditionSettingsRepository;
use Plugins\Voting\Infrastructure\Persistence\UserSubscriptionRepository;
use Plugins\Voting\Infrastructure\Persistence\VoteRepository;

final class VotingService implements VotingServiceContract
{
    public function __construct(
        private readonly ContestantRepository      $contestantRepository,
        private readonly EditionRepository         $editionRepository,
        private readonly VoteRepository            $voteRepository,
        private readonly EditionSettingsRepository $settingsRepository,
        private readonly UserSubscriptionRepository $subscriptionRepository,
        private readonly TransactionManager        $transaction,
        private readonly DomainEventCollector      $collector,
        private readonly EventBus                  $eventBus,
        private readonly RateLimiter               $rateLimiter,
        private readonly Identity                  $identity,
        private readonly int                       $cooldownHours,
    ) {}

    /** @return list<ContestantDTO> */
    public function leaderboard(string $editionId, ?string $categoryId = null): array
    {
        return array_map(
            static fn(Contestant $c): ContestantDTO => ContestantDTO::fromEntity($c),
            $this->contestantRepository->findByEdition($editionId, $categoryId),
        );
    }

    public function castVote(CastVoteDTO $dto): ContestantDTO
    {
        if ($this->identity->isGuest()) {
            throw new ServiceException('voting.cast.unauthenticated', layer: 'service.voting');
        }

        $contestant = $this->contestantRepository->find($dto->contestantId);
        if ($contestant === null) {
            throw new ServiceException(
                'voting.cast.contestant_not_found',
                layer:   'service.voting',
                context: ['contestant_id' => $dto->contestantId],
            );
        }

        $edition = $this->editionRepository->find($contestant->editionId()->value());
        if ($edition === null) {
            throw new ServiceException('voting.cast.edition_not_found', layer: 'service.voting');
        }

        if (!VotingWindowRule::check($edition)) {
            throw new ServiceException('voting.cast.edition_not_active', layer: 'service.voting');
        }

        if ($dto->ipAddress !== '') {
            // Rate limiting is delegated to pulse-engine's sliding-window
            // RateLimiter (cache-backed) instead of an ad-hoc SQL count.
            try {
                $this->rateLimiter->check($dto->ipAddress);
            } catch (RateLimitExceededException $e) {
                throw new ServiceException(
                    'voting.cast.ip_rate_limited',
                    layer:    'service.voting',
                    previous: $e,
                );
            }
            $this->rateLimiter->record($dto->ipAddress);
        }

        $settings     = $this->settingsRepository->findOrCreate($contestant->editionId());
        $subscription = $this->subscriptionRepository->findByUserAndEdition(
            $this->identity->userId,
            $contestant->editionId()->value(),
        );

        // Subscription-aware path: user has a paid subscription for this edition
        if ($settings->subscriptionEnabled()
            && $subscription !== null
            && !$subscription->level()->isFree()
        ) {
            $dailyVotes = $settings->dailyVotesForLevel($subscription->level());
            $subscription->refreshDailyAllowance($dailyVotes);

            if ($subscription->remainingVotesToday() <= 0) {
                throw new ServiceException('voting.cast.daily_limit_reached', layer: 'service.voting');
            }

            $this->collector->beginCollection();
            $this->transaction->begin();
            try {
                $subscription->deductVoteToday();

                $record = $this->voteRepository->findByUserAndContestant(
                    $this->identity->userId, $dto->contestantId,
                );
                if ($record === null) {
                    $record = VoteRecord::cast(
                        contestantId:  $contestant->id(),
                        editionId:     $edition->id(),
                        userId:        $this->identity->userId,
                        ipAddress:     $dto->ipAddress,
                        cooldownHours: 0,
                    );
                } else {
                    $record->recast($dto->ipAddress, 0);
                }

                foreach ($record->releaseEvents() as $event) {
                    $this->collector->collect($event);
                }

                $this->subscriptionRepository->save($subscription);
                $this->voteRepository->save($record);
                // Atomic SQL increments avoid read-modify-write races
                $this->contestantRepository->atomicIncrementVoteCount($contestant->id()->value());
                $this->settingsRepository->atomicIncrementVoteCount($contestant->editionId()->value());
                $this->transaction->commit();
            } catch (\Throwable $e) {
                $this->transaction->rollback();
                $this->collector->discard();
                throw new ServiceException('voting.cast.failed', layer: 'service.voting', previous: $e);
            }
        } else {
            // Free / cooldown-based path
            $record = $this->voteRepository->findByUserAndContestant(
                $this->identity->userId, $dto->contestantId,
            );

            if ($record !== null && !$record->canVoteNow()) {
                throw new ServiceException(
                    'voting.cast.cooldown_active',
                    layer:   'service.voting',
                    context: ['can_vote_again_at' => $record->canVoteAgainAt()->format(\DateTimeInterface::RFC3339)],
                );
            }

            $this->collector->beginCollection();
            $this->transaction->begin();
            try {
                if ($record === null) {
                    $record = VoteRecord::cast(
                        contestantId:  $contestant->id(),
                        editionId:     $edition->id(),
                        userId:        $this->identity->userId,
                        ipAddress:     $dto->ipAddress,
                        cooldownHours: $this->cooldownHours,
                    );
                } else {
                    $record->recast($dto->ipAddress, $this->cooldownHours);
                }

                foreach ($record->releaseEvents() as $event) {
                    $this->collector->collect($event);
                }

                $this->voteRepository->save($record);
                // Atomic SQL increments avoid read-modify-write races
                $this->contestantRepository->atomicIncrementVoteCount($contestant->id()->value());
                $this->settingsRepository->atomicIncrementVoteCount($contestant->editionId()->value());
                $this->transaction->commit();
            } catch (\Throwable $e) {
                $this->transaction->rollback();
                $this->collector->discard();
                throw new ServiceException('voting.cast.failed', layer: 'service.voting', previous: $e);
            }
        }

        foreach ($this->collector->release() as $event) {
            if ($event instanceof VoteCastDomainEvent) {
                $this->eventBus->dispatch(new VoteCastIntegrationEvent(
                    contestantId: $event->contestantId->value(),
                    editionId:    $event->editionId->value(),
                    userId:       $event->userId,
                    occurredAt:   $event->occurredAt->format(\DateTimeInterface::RFC3339),
                ));
            }
        }

        return ContestantDTO::fromEntity($contestant);
    }
}
