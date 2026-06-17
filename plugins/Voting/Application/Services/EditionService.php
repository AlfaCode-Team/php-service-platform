<?php

declare(strict_types=1);

namespace Plugins\Voting\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Voting\API\Contracts\EditionServiceContract;
use Plugins\Voting\API\DTOs\AddContestantDTO;
use Plugins\Voting\API\DTOs\ContestantDTO;
use Plugins\Voting\API\DTOs\CreateEditionDTO;
use Plugins\Voting\API\DTOs\EditionDTO;
use Plugins\Voting\API\DTOs\UpdateEditionDTO;
use Plugins\Voting\API\IntegrationEvents\ContestantAddedIntegrationEvent;
use Plugins\Voting\Domain\Entities\Contestant;
use Plugins\Voting\Domain\Entities\Edition;
use Plugins\Voting\Domain\Events\ContestantAddedDomainEvent;
use Plugins\Voting\Infrastructure\Persistence\ContestantRepository;
use Plugins\Voting\Infrastructure\Persistence\EditionRepository;

final class EditionService implements EditionServiceContract
{
    public function __construct(
        private readonly EditionRepository    $editionRepository,
        private readonly ContestantRepository $contestantRepository,
        private readonly TransactionManager   $transaction,
        private readonly DomainEventCollector $collector,
        private readonly EventBus             $eventBus,
        private readonly Identity             $identity,
    ) {}

    /** @return list<EditionDTO> */
    public function list(): array
    {
        return array_map(
            static fn(Edition $e): EditionDTO => EditionDTO::fromEntity($e),
            $this->editionRepository->findActive(),
        );
    }

    public function find(string $id): ?EditionDTO
    {
        $edition = $this->editionRepository->find($id);
        return $edition === null ? null : EditionDTO::fromEntity($edition);
    }

    public function create(CreateEditionDTO $dto): EditionDTO
    {
        if (!$this->identity->hasRole('vote_organizer')
            && !$this->identity->hasPermission('voting:manage-editions')) {
            throw new ServiceException(
                'voting.edition.create.unauthorized',
                layer: 'service.voting.edition',
            );
        }

        $this->collector->beginCollection();
        $this->transaction->begin();

        try {
            $edition = Edition::create(
                title:       $dto->title,
                slug:        $dto->slug,
                organiserId: $this->identity->userId,
                startDate:   $dto->startDate !== null ? new \DateTimeImmutable($dto->startDate) : null,
                endDate:     $dto->endDate   !== null ? new \DateTimeImmutable($dto->endDate)   : null,
            );

            $this->editionRepository->save($edition);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw new ServiceException(
                'voting.edition.create.failed',
                layer:    'service.voting.edition',
                previous: $e,
            );
        }

        $this->collector->release();

        return EditionDTO::fromEntity($edition);
    }

    public function update(string $id, UpdateEditionDTO $dto): EditionDTO
    {
        $edition = $this->requireEditionByOrganiser($id);

        $this->transaction->begin();
        try {
            $edition->update(
                title:     $dto->title,
                startDate: $dto->startDate !== null ? new \DateTimeImmutable($dto->startDate) : null,
                endDate:   $dto->endDate   !== null ? new \DateTimeImmutable($dto->endDate)   : null,
            );

            $this->editionRepository->save($edition);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw new ServiceException(
                'voting.edition.update.failed',
                layer:    'service.voting.edition',
                context:  ['id' => $id],
                previous: $e,
            );
        }

        return EditionDTO::fromEntity($edition);
    }

    public function activate(string $id): EditionDTO
    {
        $edition = $this->requireEditionByOrganiser($id);

        $this->transaction->begin();
        try {
            $edition->activate();
            $this->editionRepository->save($edition);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw new ServiceException(
                'voting.edition.activate.failed',
                layer:    'service.voting.edition',
                context:  ['id' => $id],
                previous: $e,
            );
        }

        return EditionDTO::fromEntity($edition);
    }

    public function close(string $id): EditionDTO
    {
        $edition = $this->requireEditionByOrganiser($id);

        $this->transaction->begin();
        try {
            $edition->close();
            $this->editionRepository->save($edition);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw new ServiceException(
                'voting.edition.close.failed',
                layer:    'service.voting.edition',
                context:  ['id' => $id],
                previous: $e,
            );
        }

        return EditionDTO::fromEntity($edition);
    }

    public function addContestant(string $editionId, AddContestantDTO $dto): ContestantDTO
    {
        $edition = $this->requireEditionByOrganiser($editionId);

        $this->collector->beginCollection();
        $this->transaction->begin();

        try {
            $contestant = Contestant::add(
                editionId:   $edition->id(),
                organiserId: $edition->organiserId(),
                fullName:    $dto->fullName,
                slug:        $dto->slug,
                avatarId:    $dto->avatarId,
                detail:      $dto->detail,
                categoryId:  $dto->categoryId,
            );

            foreach ($contestant->releaseEvents() as $event) {
                $this->collector->collect($event);
            }

            $this->contestantRepository->save($contestant);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw new ServiceException(
                'voting.contestant.add.failed',
                layer:    'service.voting.edition',
                context:  ['edition_id' => $editionId],
                previous: $e,
            );
        }

        foreach ($this->collector->release() as $event) {
            if ($event instanceof ContestantAddedDomainEvent) {
                $this->eventBus->dispatch(new ContestantAddedIntegrationEvent(
                    contestantId: $event->contestantId->value(),
                    editionId:    $event->editionId->value(),
                    fullName:     $event->fullName,
                    occurredAt:   $event->occurredAt->format(\DateTimeInterface::RFC3339),
                ));
            }
        }

        return ContestantDTO::fromEntity($contestant);
    }

    public function removeContestant(string $contestantId): bool
    {
        $contestant = $this->contestantRepository->find($contestantId);
        if ($contestant === null) {
            return false;
        }

        $this->requireEditionByOrganiser($contestant->editionId()->value());

        $this->transaction->begin();
        try {
            $deleted = $this->contestantRepository->delete($contestantId);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw new ServiceException(
                'voting.contestant.remove.failed',
                layer:    'service.voting.edition',
                context:  ['contestant_id' => $contestantId],
                previous: $e,
            );
        }

        return $deleted;
    }

    private function requireEditionByOrganiser(string $editionId): Edition
    {
        $edition = $this->editionRepository->find($editionId);

        if ($edition === null) {
            throw new ServiceException(
                'voting.edition.not_found',
                layer:   'service.voting.edition',
                context: ['edition_id' => $editionId],
            );
        }

        if ($edition->organiserId() !== $this->identity->userId
            && !$this->identity->hasPermission('voting:manage-editions')) {
            throw new ServiceException(
                'voting.edition.unauthorized',
                layer:   'service.voting.edition',
                context: ['edition_id' => $editionId],
            );
        }

        return $edition;
    }
}
