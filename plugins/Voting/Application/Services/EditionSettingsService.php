<?php

declare(strict_types=1);

namespace Plugins\Voting\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Voting\API\Contracts\EditionSettingsServiceContract;
use Plugins\Voting\API\DTOs\EditionSettingsDTO;
use Plugins\Voting\API\DTOs\UpdateEditionSettingsDTO;
use Plugins\Voting\Infrastructure\Persistence\EditionRepository;
use Plugins\Voting\Infrastructure\Persistence\EditionSettingsRepository;

final class EditionSettingsService implements EditionSettingsServiceContract
{
    public function __construct(
        private readonly EditionSettingsRepository $settingsRepository,
        private readonly EditionRepository         $editionRepository,
        private readonly TransactionManager        $transaction,
        private readonly Identity                  $identity,
    ) {}

    public function get(string $editionId): EditionSettingsDTO
    {
        $edition = $this->editionRepository->find($editionId);
        if ($edition === null) {
            throw new ServiceException(
                'voting.edition_settings.edition_not_found',
                layer:   'service.voting.settings',
                context: ['edition_id' => $editionId],
            );
        }

        $settings = $this->settingsRepository->findOrCreate($edition->id());
        return EditionSettingsDTO::fromEntity($settings);
    }

    public function update(string $editionId, UpdateEditionSettingsDTO $dto): EditionSettingsDTO
    {
        $edition = $this->editionRepository->find($editionId);
        if ($edition === null) {
            throw new ServiceException(
                'voting.edition_settings.edition_not_found',
                layer:   'service.voting.settings',
                context: ['edition_id' => $editionId],
            );
        }

        if ($edition->organiserId() !== $this->identity->userId
            && !$this->identity->hasPermission('voting:manage-editions')) {
            throw new ServiceException(
                'voting.edition_settings.unauthorized',
                layer: 'service.voting.settings',
            );
        }

        $settings = $this->settingsRepository->findOrCreate($edition->id());

        $this->transaction->begin();
        try {
            if ($dto->nominationEnabled !== null || $dto->nominationStartDate !== null
                || $dto->nominationEndDate !== null || $dto->nominationFields !== null) {
                $settings->updateNomination(
                    enabled:   $dto->nominationEnabled   ?? $settings->nominationEnabled(),
                    startDate: $dto->nominationStartDate !== null
                        ? new \DateTimeImmutable($dto->nominationStartDate)
                        : $settings->nominationStartDate(),
                    endDate:   $dto->nominationEndDate !== null
                        ? new \DateTimeImmutable($dto->nominationEndDate)
                        : $settings->nominationEndDate(),
                    fields:    $dto->nominationFields ?? $settings->nominationFields(),
                );
            }

            if ($dto->subscriptionEnabled !== null || $dto->subscriptionPlans !== null) {
                $settings->updateSubscription(
                    enabled: $dto->subscriptionEnabled ?? $settings->subscriptionEnabled(),
                    plans:   $dto->subscriptionPlans   ?? $settings->subscriptionPlans(),
                );
            }

            if ($dto->boostingEnabled !== null || $dto->currency !== null || $dto->boostTiers !== null) {
                $settings->updateBoosting(
                    enabled:  $dto->boostingEnabled ?? $settings->boostingEnabled(),
                    currency: $dto->currency        ?? $settings->currency(),
                    tiers:    $dto->boostTiers      ?? $settings->boostTiers(),
                );
            }

            if ($dto->categories !== null) {
                $settings->replaceCategories($dto->categories);
            }

            if ($dto->bannerId !== null || $dto->thumbnailId !== null || $dto->tags !== null) {
                $settings->updateDisplay(
                    bannerId:    $dto->bannerId    ?? $settings->bannerId(),
                    thumbnailId: $dto->thumbnailId ?? $settings->thumbnailId(),
                    tags:        $dto->tags        ?? $settings->tags(),
                );
            }

            $this->settingsRepository->save($settings);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw new ServiceException(
                'voting.edition_settings.update_failed',
                layer:    'service.voting.settings',
                context:  ['edition_id' => $editionId],
                previous: $e,
            );
        }

        return EditionSettingsDTO::fromEntity($settings);
    }
}
