<?php

declare(strict_types=1);

namespace Plugins\Settings\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Settings\API\Contracts\SettingsServiceContract;
use Plugins\Settings\API\DTOs\CompanySettingsDTO;
use Plugins\Settings\API\DTOs\ContactSettingsDTO;
use Plugins\Settings\API\DTOs\EmailProviderSettingsDTO;
use Plugins\Settings\API\DTOs\EmailSettingsDTO;
use Plugins\Settings\API\DTOs\SystemSettingsDTO;
use Plugins\Settings\Domain\ValueObjects\SettingsSection;
use Plugins\Settings\Infrastructure\Persistence\SettingsRepository;

final class SettingsService implements SettingsServiceContract
{
    public function __construct(
        private readonly SettingsRepository $repository,
        private readonly TransactionManager $transaction,
        private readonly Identity $identity,
    ) {}

    public function company(string $tenantId): CompanySettingsDTO
    {
        $row = $this->repository->fetch(SettingsSection::Company, $tenantId);

        return $row === null
            ? CompanySettingsDTO::defaults($tenantId)
            : CompanySettingsDTO::fromRow($row);
    }

    public function saveCompany(CompanySettingsDTO $settings): CompanySettingsDTO
    {
        $this->persist(SettingsSection::Company, $settings->toRow());

        return $settings;
    }

    public function updateCompanyLogo(string $tenantId, string $logoPath): CompanySettingsDTO
    {
        $updated = $this->company($tenantId)->withLogo($logoPath);
        $this->persist(SettingsSection::Company, $updated->toRow());

        return $updated;
    }

    public function removeCompanyLogo(string $tenantId): CompanySettingsDTO
    {
        $updated = $this->company($tenantId)->withLogo(null);
        $this->persist(SettingsSection::Company, $updated->toRow());

        return $updated;
    }

    public function contact(string $tenantId): ContactSettingsDTO
    {
        $row = $this->repository->fetch(SettingsSection::Contact, $tenantId);

        return $row === null
            ? ContactSettingsDTO::defaults($tenantId)
            : ContactSettingsDTO::fromRow($row);
    }

    public function saveContact(ContactSettingsDTO $settings): ContactSettingsDTO
    {
        $this->persist(SettingsSection::Contact, $settings->toRow());

        return $settings;
    }

    public function email(string $tenantId): EmailSettingsDTO
    {
        $row = $this->repository->fetch(SettingsSection::Email, $tenantId);

        return $row === null
            ? EmailSettingsDTO::defaults($tenantId)
            : EmailSettingsDTO::fromRow($row);
    }

    public function saveEmail(EmailSettingsDTO $settings): EmailSettingsDTO
    {
        $this->persist(SettingsSection::Email, $settings->toRow());

        return $settings;
    }

    public function emailProviders(string $tenantId): EmailProviderSettingsDTO
    {
        $row = $this->repository->fetch(SettingsSection::EmailProviders, $tenantId);

        return $row === null
            ? EmailProviderSettingsDTO::defaults($tenantId)
            : EmailProviderSettingsDTO::fromRow($row);
    }

    public function saveEmailProviders(EmailProviderSettingsDTO $settings): EmailProviderSettingsDTO
    {
        $this->persist(SettingsSection::EmailProviders, $settings->toRow());

        return $settings;
    }

    public function system(string $tenantId): SystemSettingsDTO
    {
        $row = $this->repository->fetch(SettingsSection::System, $tenantId);

        return $row === null
            ? SystemSettingsDTO::defaults($tenantId)
            : SystemSettingsDTO::fromRow($row);
    }

    public function saveSystem(SystemSettingsDTO $settings): SystemSettingsDTO
    {
        $this->persist(SettingsSection::System, $settings->toRow());

        return $settings;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function persist(SettingsSection $section, array $row): void
    {
        $this->assertCanManage($section);

        $this->transaction->begin();
        try {
            $this->repository->upsert($section, $row);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw new ServiceException(
                "settings.{$section->value}.save.failed",
                layer: 'service.settings',
                context: ['section' => $section->value],
                previous: $e,
            );
        }
    }

    /**
     * Writing tenant settings is an administrative action: the caller must hold
     * the `settings:manage` permission, or an `admin`/`super` role. Reads are
     * open to any authenticated tenant member. (The route's `auth` filter and the
     * controller's tenant-scope guard run before this.)
     */
    private function assertCanManage(SettingsSection $section): void
    {
        if ($this->identity->hasPermission('settings:manage')
            || $this->identity->hasRole('admin')
            || $this->identity->hasRole('super')) {
            return;
        }

        throw new ServiceException(
            "settings.{$section->value}.update.unauthorized",
            layer: 'service.settings',
            context: ['section' => $section->value, 'user' => $this->identity->userId],
        );
    }
}
