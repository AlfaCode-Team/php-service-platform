<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\User\Domain\ValueObjects\Theme;

/**
 * Validated preferences-update input (idempotent full replace). User id comes
 * from the Identity, never the body.
 */
final readonly class UpdatePreferencesDTO
{
    public function __construct(
        public ?string $language,
        public ?string $currency,
        public Theme $theme,
        public bool $reduceMotion,
        public bool $largerText,
        public bool $highContrast,
        public bool $screenReaderHints,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $errors = [];

        $language = self::trimOrNull($request->input('language'));
        $currency = self::trimOrNull($request->input('currency'));

        if ($language !== null && !preg_match('/^[a-zA-Z]{2,10}(-[a-zA-Z]{2,10})?$/', $language)) {
            $errors['language'] = 'Language must be a 2–10 letter tag, e.g. en or en-GB.';
        }
        if ($currency !== null && !preg_match('/^[a-zA-Z]{3}$/', $currency)) {
            $errors['currency'] = 'Currency must be a 3-letter ISO 4217 code, e.g. UGX.';
        }

        $theme = Theme::System;
        try {
            $theme = Theme::fromString((string) $request->input('theme', 'system'));
        } catch (\DomainException $e) {
            $errors['theme'] = $e->getMessage();
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            language:          $language,
            currency:          $currency,
            theme:             $theme,
            reduceMotion:      $request->boolean('reduceMotion'),
            largerText:        $request->boolean('largerText'),
            highContrast:      $request->boolean('highContrast'),
            screenReaderHints: $request->boolean('screenReaderHints'),
        );
    }

    private static function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
