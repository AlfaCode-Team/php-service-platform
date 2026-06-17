<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

final readonly class UpdateEditionSettingsDTO
{
    public function __construct(
        // Nomination
        public ?bool   $nominationEnabled,
        public ?string $nominationStartDate,
        public ?string $nominationEndDate,
        public ?array  $nominationFields,
        // Subscription
        public ?bool   $subscriptionEnabled,
        public ?array  $subscriptionPlans,
        // Boosting
        public ?bool   $boostingEnabled,
        public ?string $currency,
        public ?array  $boostTiers,
        // Award categories – null means "don't touch", [] means "clear all"
        public ?array  $categories,
        // Display
        public ?string $bannerId,
        public ?string $thumbnailId,
        public ?array  $tags,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $errors = [];

        $nomStart = trim((string) $request->input('nomination_start_date', '')) ?: null;
        $nomEnd   = trim((string) $request->input('nomination_end_date', ''))   ?: null;

        if ($nomStart !== null) {
            try { new \DateTimeImmutable($nomStart); } catch (\Throwable) {
                $errors['nomination_start_date'] = 'Must be a valid date.';
            }
        }
        if ($nomEnd !== null) {
            try { new \DateTimeImmutable($nomEnd); } catch (\Throwable) {
                $errors['nomination_end_date'] = 'Must be a valid date.';
            }
        }

        $currency = trim((string) $request->input('currency', '')) ?: null;
        if ($currency !== null && !preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'] = 'Currency must be a 3-letter ISO-4217 code.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $toNullableBool = static fn(mixed $v): ?bool => $v === null ? null : (bool) $v;
        $toNullableArr  = static fn(mixed $v): ?array => is_array($v) ? $v : null;

        return new self(
            nominationEnabled:   $toNullableBool($request->input('nomination_enabled')),
            nominationStartDate: $nomStart,
            nominationEndDate:   $nomEnd,
            nominationFields:    $toNullableArr($request->input('nomination_fields')),
            subscriptionEnabled: $toNullableBool($request->input('subscription_enabled')),
            subscriptionPlans:   $toNullableArr($request->input('subscription_plans')),
            boostingEnabled:     $toNullableBool($request->input('boosting_enabled')),
            currency:            $currency,
            boostTiers:          $toNullableArr($request->input('boost_tiers')),
            categories:          $toNullableArr($request->input('categories')),
            bannerId:            trim((string) $request->input('banner_id', ''))    ?: null,
            thumbnailId:         trim((string) $request->input('thumbnail_id', '')) ?: null,
            tags:                $toNullableArr($request->input('tags')),
        );
    }
}
