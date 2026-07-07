<?php

declare(strict_types=1);

namespace Plugins\User\Domain\Entities;

use Plugins\User\Domain\ValueObjects\Theme;
use Project\Support\Entity\Entity;

/**
 * UserPreferences — mirrors the TENANT-scoped `user_preferences` table (one row
 * per user). Locale/currency/theme plus accessibility toggles.
 *
 * Built on the shared {@see Entity} attribute-bag base, keyed by DB column. The
 * accessibility flags use the `int-bool` cast so toRawArray() emits 0/1 while
 * the getters read native booleans.
 */
final class UserPreferences extends Entity
{
    private const DEFAULT_LANGUAGE = 'en';
    private const DEFAULT_CURRENCY = 'UGX';

    protected string $primaryKey = 'user_id';

    /** @var array<string, string> */
    protected array $casts = [
        'reduce_motion'       => 'int-bool',
        'larger_text'         => 'int-bool',
        'high_contrast'       => 'int-bool',
        'screen_reader_hints' => 'int-bool',
    ];

    public static function fromInput(
        string $userId,
        ?string $language,
        ?string $currency,
        Theme $theme,
        bool $reduceMotion,
        bool $largerText,
        bool $highContrast,
        bool $screenReaderHints,
    ): self {
        return self::guarded([
            'user_id'             => $userId,
            'language'            => self::blankTo($language, self::DEFAULT_LANGUAGE),
            'currency'            => strtoupper(self::blankTo($currency, self::DEFAULT_CURRENCY)),
            'theme'               => $theme->value,
            'reduce_motion'       => $reduceMotion,
            'larger_text'         => $largerText,
            'high_contrast'       => $highContrast,
            'screen_reader_hints' => $screenReaderHints,
        ]);
    }

    public static function defaults(string $userId): self
    {
        return self::guarded([
            'user_id'             => $userId,
            'language'            => self::DEFAULT_LANGUAGE,
            'currency'            => self::DEFAULT_CURRENCY,
            'theme'               => Theme::System->value,
            'reduce_motion'       => false,
            'larger_text'         => false,
            'high_contrast'       => false,
            'screen_reader_hints' => false,
        ]);
    }

    /** @param array<string,mixed> $attrs Validate, then hydrate the bag. */
    private static function guarded(array $attrs): self
    {
        $userId = (string) $attrs['user_id'];
        if ($userId === '' || mb_strlen($userId) > 31) {
            throw new \DomainException('UserPreferences requires a valid user id.');
        }
        if (!preg_match('/^[a-zA-Z]{2,10}(-[a-zA-Z]{2,10})?$/', (string) $attrs['language'])) {
            throw new \DomainException('Language must be a 2–10 letter tag, e.g. en or en-GB.');
        }
        if (!preg_match('/^[A-Z]{3}$/', (string) $attrs['currency'])) {
            throw new \DomainException('Currency must be a 3-letter ISO 4217 code, e.g. UGX.');
        }
        // Validate the theme is a known enum case.
        Theme::from((string) $attrs['theme']);

        $p = (new self())->forceFill($attrs);
        $p->syncOriginal();

        return $p;
    }

    private static function blankTo(?string $value, string $default): string
    {
        $value = $value === null ? '' : trim($value);
        return $value === '' ? $default : $value;
    }

    public function userId(): string          { return $this->getString('user_id'); }
    public function language(): string        { return $this->getString('language'); }
    public function currency(): string        { return $this->getString('currency'); }
    public function theme(): Theme            { return Theme::from($this->getString('theme')); }
    public function reduceMotion(): bool      { return $this->getBool('reduce_motion'); }
    public function largerText(): bool        { return $this->getBool('larger_text'); }
    public function highContrast(): bool      { return $this->getBool('high_contrast'); }
    public function screenReaderHints(): bool { return $this->getBool('screen_reader_hints'); }

    /** @return array<string, mixed> Camel-cased API shape (not the DB shape). */
    public function toArray(bool $onlyChanged = false): array
    {
        return [
            'userId'            => $this->userId(),
            'language'          => $this->language(),
            'currency'          => $this->currency(),
            'theme'             => $this->theme()->value,
            'reduceMotion'      => $this->reduceMotion(),
            'largerText'        => $this->largerText(),
            'highContrast'      => $this->highContrast(),
            'screenReaderHints' => $this->screenReaderHints(),
        ];
    }
}
