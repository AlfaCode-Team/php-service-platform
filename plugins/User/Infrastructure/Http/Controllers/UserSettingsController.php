<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\User\API\DTOs\UpdateNotificationPreferencesDTO;
use Plugins\User\API\DTOs\UpdatePreferencesDTO;
use Plugins\User\API\DTOs\UpdatePrivacyDTO;
use Plugins\User\API\DTOs\UpdateProfileDTO;
use Plugins\User\Application\Services\UserSettingsService;
use Project\Http\Controllers\ApiController;

/**
 * Thin HTTP boundary for the authenticated user's settings (self-scoped). One
 * controller for the four settings resources; each action is DTO → service →
 * Response and serialises the returned entity via toArray().
 */
final class UserSettingsController extends ApiController
{
    public function __construct(
        private readonly UserSettingsService $settings,
    ) {}

    public function showProfile(): Response
    {
        return $this->ok($this->settings->getProfile()->toArray());
    }

    public function updateProfile(): Response
    {
        $dto = UpdateProfileDTO::fromRequest($this->resolveRequest());
        return $this->ok($this->settings->updateProfile($dto)->toArray());
    }

    public function showPreferences(): Response
    {
        return $this->ok($this->settings->getPreferences()->toArray());
    }

    public function updatePreferences(): Response
    {
        $dto = UpdatePreferencesDTO::fromRequest($this->resolveRequest());
        return $this->ok($this->settings->updatePreferences($dto)->toArray());
    }

    public function showPrivacy(): Response
    {
        return $this->ok($this->settings->getPrivacy()->toArray());
    }

    public function updatePrivacy(): Response
    {
        $dto = UpdatePrivacyDTO::fromRequest($this->resolveRequest());
        return $this->ok($this->settings->updatePrivacy($dto)->toArray());
    }

    public function showNotifications(): Response
    {
        return $this->ok($this->settings->getNotifications()->toArray());
    }

    public function updateNotifications(): Response
    {
        $dto = UpdateNotificationPreferencesDTO::fromRequest($this->resolveRequest());
        return $this->ok($this->settings->updateNotifications($dto)->toArray());
    }
}
