<?php

declare(strict_types=1);

namespace Plugins\Settings\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Validation\Validator;

/**
 * OptionsDTO: company branding & identity — mirrors `tenant_settings_company`.
 */
final readonly class CompanySettingsDTO
{
    /**
     * @param array<int|string, mixed>|null $socialLinks
     * @param array<int|string, mixed>|null $serviceTypes
     */
    public function __construct(
        public string $tenantId,
        public string $name = 'Acme Inc',
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $phoneArea = null,
        public ?string $address = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $country = null,
        public string $timezone = 'UTC',
        public ?string $logo = null,
        public ?string $description = null,
        public ?string $founder = null,
        public ?string $foundedYear = null,
        public ?array $socialLinks = null,
        public ?array $serviceTypes = null,
    ) {}

    /** Hard-coded defaults for a tenant with no stored row yet. */
    public static function defaults(string $tenantId): self
    {
        return new self($tenantId);
    }

    /** Immutable copy with the logo path replaced (or cleared with null). */
    public function withLogo(?string $logo): self
    {
        return new self(
            tenantId:     $this->tenantId,
            name:         $this->name,
            email:        $this->email,
            phone:        $this->phone,
            phoneArea:    $this->phoneArea,
            address:      $this->address,
            city:         $this->city,
            region:       $this->region,
            country:      $this->country,
            timezone:     $this->timezone,
            logo:         $logo,
            description:  $this->description,
            founder:      $this->founder,
            foundedYear:  $this->foundedYear,
            socialLinks:  $this->socialLinks,
            serviceTypes: $this->serviceTypes,
        );
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            tenantId:     (string) $row['tenant_id'],
            name:         (string) ($row['company_name'] ?? 'Acme Inc'),
            email:        self::str($row['company_email'] ?? null),
            phone:        self::str($row['company_phone'] ?? null),
            phoneArea:    self::str($row['company_phone_area'] ?? null),
            address:      self::str($row['company_address'] ?? null),
            city:         self::str($row['company_city'] ?? null),
            region:       self::str($row['company_region'] ?? null),
            country:      self::str($row['company_country'] ?? null),
            timezone:     (string) ($row['company_timezone'] ?? 'UTC'),
            logo:         self::str($row['company_logo'] ?? null),
            description:  self::str($row['company_description'] ?? null),
            founder:      self::str($row['company_founder'] ?? null),
            foundedYear:  self::str($row['company_founded_year'] ?? null),
            socialLinks:  self::json($row['company_social_links'] ?? null),
            serviceTypes: self::json($row['company_service_types'] ?? null),
        );
    }

    /**
     * Build from request input merged over `$base` (the tenant's current stored
     * settings), so an absent field keeps its existing value — a partial update,
     * never a reset to defaults.
     */
    public static function fromRequest(Request $request, self $base): self
    {
        Validator::make($request->all(), [
            'company_name'          => 'nullable|string|max:128',
            'company_email'         => 'nullable|email|max:191',
            'company_phone'         => 'nullable|string|max:32',
            'company_phone_area'    => 'nullable|string|max:16',
            'company_address'       => 'nullable|string|max:255',
            'company_city'          => 'nullable|string|max:64',
            'company_region'        => 'nullable|string|max:64',
            'company_country'       => 'nullable|string|between:2,2',
            'company_timezone'      => 'nullable|string|max:64',
            'company_logo'          => 'nullable|string|max:255',
            'company_description'   => 'nullable|string',
            'company_founder'       => 'nullable|string|max:128',
            'company_founded_year'  => 'nullable|regex:/^\d{4}$/',
            'company_social_links'  => 'nullable|array',
            'company_service_types' => 'nullable|array',
        ])->validate();

        $d = $base;

        return new self(
            tenantId:     $base->tenantId,
            name:         $request->string('company_name') ?: $d->name,
            email:        $request->input('company_email', $d->email),
            phone:        $request->input('company_phone', $d->phone),
            phoneArea:    $request->input('company_phone_area', $d->phoneArea),
            address:      $request->input('company_address', $d->address),
            city:         $request->input('company_city', $d->city),
            region:       $request->input('company_region', $d->region),
            country:      $request->input('company_country', $d->country),
            timezone:     $request->string('company_timezone') ?: $d->timezone,
            logo:         $request->input('company_logo', $d->logo),
            description:  $request->input('company_description', $d->description),
            founder:      $request->input('company_founder', $d->founder),
            foundedYear:  $request->input('company_founded_year', $d->foundedYear),
            socialLinks:  $request->input('company_social_links', $d->socialLinks),
            serviceTypes: $request->input('company_service_types', $d->serviceTypes),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenant_id'             => $this->tenantId,
            'company_name'          => $this->name,
            'company_email'         => $this->email,
            'company_phone'         => $this->phone,
            'company_phone_area'    => $this->phoneArea,
            'company_address'       => $this->address,
            'company_city'          => $this->city,
            'company_region'        => $this->region,
            'company_country'       => $this->country,
            'company_timezone'      => $this->timezone,
            'company_logo'          => $this->logo,
            'company_description'   => $this->description,
            'company_founder'       => $this->founder,
            'company_founded_year'  => $this->foundedYear,
            'company_social_links'  => $this->socialLinks,
            'company_service_types' => $this->serviceTypes,
        ];
    }

    /** @return array<string, mixed> Row shape for persistence (JSON columns encoded). */
    public function toRow(): array
    {
        $row = $this->toArray();
        $row['company_social_links']  = $this->socialLinks  === null ? null : json_encode($this->socialLinks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row['company_service_types'] = $this->serviceTypes === null ? null : json_encode($this->serviceTypes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $row;
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    /** @return array<int|string, mixed>|null */
    private static function json(mixed $v): ?array
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_array($v)) {
            return $v;
        }
        $decoded = json_decode((string) $v, true);

        return is_array($decoded) ? $decoded : null;
    }
}
