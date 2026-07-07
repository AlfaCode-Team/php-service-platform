<?php

declare(strict_types=1);

namespace Plugins\Settings\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Settings\API\Contracts\SettingsServiceContract;
use Plugins\Settings\API\DTOs\CompanySettingsDTO;
use Plugins\Settings\API\DTOs\ContactSettingsDTO;
use Plugins\Settings\API\DTOs\EmailProviderSettingsDTO;
use Plugins\Settings\API\DTOs\EmailSettingsDTO;
use Plugins\Settings\API\DTOs\SystemSettingsDTO;

final class SettingsController
{
    /** Allowed logo upload extensions. */
    private const LOGO_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'];

    public function __construct(
        private readonly SettingsServiceContract $settings,
        private readonly Identity $identity,
    ) {}

    public function company(Request $request): Response
    {
        return $this->scoped(fn(string $t) =>
            Response::json(['data' => $this->settings->company($t)->toArray()]));
    }

    public function saveCompany(Request $request): Response
    {
        return $this->scoped(fn(string $t) =>
            Response::json(['data' => $this->settings->saveCompany(
                CompanySettingsDTO::fromRequest($request, $this->settings->company($t))
            )->toArray()]));
    }

    /**
     * Upload (replace) the company logo. Stores the blob via StoragePort and
     * persists its path onto the company settings; the previous blob is deleted.
     * Route MUST declare `"requires": ["storage.local"]`.
     */
    public function uploadCompanyLogo(Request $request): Response
    {
        return $this->scoped(function (string $t) use ($request) {
            $file = $request->file('company_logo');
            if ($file === null || !$file->isValid()) {
                return Response::unprocessable(['company_logo' => 'A valid image upload is required.']);
            }

            $ext = strtolower($file->extension());
            if (!in_array($ext, self::LOGO_EXTENSIONS, true)) {
                return Response::unprocessable([
                    'company_logo' => 'Logo must be an image (' . implode(', ', self::LOGO_EXTENSIONS) . ').',
                ]);
            }

            $storage = $this->storage($request);
            if ($storage === null) {
                return Response::serverError('Storage backend is not available.');
            }

            $previous = $this->settings->company($t)->logo;
            $name     = bin2hex(random_bytes(8)) . '.' . $ext;
            $path     = $storage->store($file->contents(), $name, "tenants/{$t}/branding", 'public');

            try {
                $dto = $this->settings->updateCompanyLogo($t, $path);
            } catch (\Throwable $e) {
                $storage->delete($path);   // no orphan blob on authz/persist failure
                throw $e;
            }

            if ($previous !== null && $previous !== '' && $previous !== $path) {
                $storage->delete($previous);
            }

            return Response::json([
                'data'     => $dto->toArray(),
                'logo_url' => $storage->temporaryUrl($path),
            ]);
        });
    }

    /**
     * Remove the company logo: clears the settings path and deletes the blob.
     * Route MUST declare `"requires": ["storage.local"]`.
     */
    public function removeCompanyLogo(Request $request): Response
    {
        return $this->scoped(function (string $t) use ($request) {
            $previous = $this->settings->company($t)->logo;
            $dto      = $this->settings->removeCompanyLogo($t);

            if ($previous !== null && $previous !== '') {
                $this->storage($request)?->delete($previous);
            }

            return Response::json(['data' => $dto->toArray()]);
        });
    }

    public function contact(Request $request): Response
    {
        return $this->scoped(fn(string $t) =>
            Response::json(['data' => $this->settings->contact($t)->toArray()]));
    }

    public function saveContact(Request $request): Response
    {
        return $this->scoped(fn(string $t) =>
            Response::json(['data' => $this->settings->saveContact(
                ContactSettingsDTO::fromRequest($request, $this->settings->contact($t))
            )->toArray()]));
    }

    public function email(Request $request): Response
    {
        return $this->scoped(fn(string $t) =>
            Response::json(['data' => $this->settings->email($t)->toArray()]));
    }

    public function saveEmail(Request $request): Response
    {
        return $this->scoped(fn(string $t) =>
            Response::json(['data' => $this->settings->saveEmail(
                EmailSettingsDTO::fromRequest($request, $this->settings->email($t))
            )->toArray()]));
    }

    public function emailProviders(Request $request): Response
    {
        return $this->scoped(fn(string $t) =>
            Response::json(['data' => $this->settings->emailProviders($t)->toArray()]));
    }

    public function saveEmailProviders(Request $request): Response
    {
        return $this->scoped(fn(string $t) =>
            Response::json(['data' => $this->settings->saveEmailProviders(
                EmailProviderSettingsDTO::fromRequest($request, $this->settings->emailProviders($t))
            )->toArray()]));
    }

    public function system(Request $request): Response
    {
        return $this->scoped(fn(string $t) =>
            Response::json(['data' => $this->settings->system($t)->toArray()]));
    }

    public function saveSystem(Request $request): Response
    {
        return $this->scoped(fn(string $t) =>
            Response::json(['data' => $this->settings->saveSystem(
                SystemSettingsDTO::fromRequest($request, $this->settings->system($t))
            )->toArray()]));
    }

    /**
     * Run a handler with the acting tenant id, which comes from the authenticated
     * Identity (the signed `tnt` claim) — never from client input, so a caller can
     * only read/write its own tenant's settings. An unscoped (central) token has
     * no tenant settings to act on, so it is rejected rather than silently keyed
     * to the empty string.
     *
     * @param callable(string): Response $handler
     */
    private function scoped(callable $handler): Response
    {
        $tenantId = $this->identity->tenantId;
        if ($tenantId === '') {
            return Response::forbidden('A tenant-scoped token is required to access settings.');
        }

        return $handler($tenantId);
    }

    /**
     * Resolve the on-demand StoragePort from the request container. Bound only on
     * routes that declare `"requires": ["storage.local"]`; null otherwise.
     */
    private function storage(Request $request): ?StoragePort
    {
        $container = $request->container();
        if ($container === null || !$container->has(StoragePort::class)) {
            return null;
        }

        return $container->make(StoragePort::class);
    }
}
