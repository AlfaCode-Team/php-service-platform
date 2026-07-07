<?php

declare(strict_types=1);

namespace Project\Support\Casting;

use InvalidArgumentException;
use Project\Support\Casting\Casts\ArrayCast;
use Project\Support\Casting\Casts\BooleanCast;
use Project\Support\Casting\Casts\CSVCast;
use Project\Support\Casting\Casts\DatetimeCast;
use Project\Support\Casting\Casts\FloatCast;
use Project\Support\Casting\Casts\IntBoolCast;
use Project\Support\Casting\Casts\IntegerCast;
use Project\Support\Casting\Casts\JsonCast;
use Project\Support\Casting\Casts\ObjectCast;
use Project\Support\Casting\Casts\StringCast;
use Project\Support\Casting\Casts\TimestampCast;

/**
 * Stateless, dependency-free type caster.
 *
 * Transforms scalar/array/date values between their DataSource (DB) form and
 * their PHP form, in both directions ("get" reads from the DataSource, "set"
 * writes to it). This is a Project-layer support utility — it performs NO I/O,
 * resolves NO container, and reads NO globals, so a Hydrator/Repository can use
 * it freely without breaking GDA layer rules.
 *
 * Ported from the legacy HKM\lib\DataCaster, decoupled from the Active-Record
 * Entity and from Carbon/BaseConnection.
 *
 * @see TypeParser for the accepted type-string grammar
 */
final class DataCaster
{
    /** @var array<string, class-string<CastInterface>> */
    private static array $defaultHandlers = [];

    /** @var array<string, string> [field => type] */
    private array $types = [];

    /** @var array<string, class-string<CastInterface>> [type => handler] */
    private array $castHandlers;

    /** @var array<string, bool> */
    private array $validatedHandlers = [];

    /** @var array<string, array{nullable: bool, baseType: string, params: list<string>}> */
    private array $parsedTypes = [];

    /**
     * @param array<string, class-string<CastInterface>>|null $castHandlers Custom handlers (merged over defaults)
     * @param array<string, string>|null                      $types        [field => type]
     */
    public function __construct(
        ?array $castHandlers = null,
        ?array $types = null,
        private readonly ?object $helper = null,
        private readonly bool $strict = true,
    ) {
        if (self::$defaultHandlers === []) {
            self::$defaultHandlers = [
                'array'     => ArrayCast::class,
                'bool'      => BooleanCast::class,
                'boolean'   => BooleanCast::class,
                'csv'       => CSVCast::class,
                'datetime'  => DatetimeCast::class,
                'double'    => FloatCast::class,
                'float'     => FloatCast::class,
                'int'       => IntegerCast::class,
                'integer'   => IntegerCast::class,
                'int-bool'  => IntBoolCast::class,
                'json'      => JsonCast::class,
                'object'    => ObjectCast::class,
                'string'    => StringCast::class,
                'timestamp' => TimestampCast::class,
            ];
        }

        $this->castHandlers = $castHandlers
            ? array_merge(self::$defaultHandlers, $castHandlers)
            : self::$defaultHandlers;

        if ($types !== null) {
            $this->setTypes($types);
        }
    }

    /**
     * @param array<string, string> $types [field => type]
     */
    public function setTypes(array $types): static
    {
        $this->types = $types;
        $this->parsedTypes = [];

        return $this;
    }

    /**
     * Cast $value for $field. $method is 'get' (DataSource -> PHP) or 'set' (PHP -> DataSource).
     *
     * Prefix a type with "?" (e.g. "?string") to pass null through untouched.
     *
     * @phpstan-param 'get'|'set' $method
     */
    public function castAs(mixed $value, string $field, string $method = 'get'): mixed
    {
        if (! isset($this->types[$field])) {
            return $value;
        }

        $parsed = $this->parsedTypes[$field]
            ??= TypeParser::parse($this->types[$field]);

        $isNullable = $parsed['nullable'];
        $type = $parsed['baseType'];
        $params = $parsed['params'];

        if ($value === null) {
            if ($isNullable) {
                return null;
            }
            if ($this->strict) {
                throw new InvalidArgumentException(
                    'Field "' . $field . '" is not nullable, but null was passed.'
                );
            }
        }

        if ($isNullable && ! $this->strict) {
            $params[] = 'nullable';
        }

        if (! isset($this->castHandlers[$type])) {
            throw new InvalidArgumentException(
                'No such handler for "' . $field . '". Invalid type: ' . $type
            );
        }

        $handler = $this->castHandlers[$type];

        if ($this->strict && ! $this->isValidHandler($handler)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid handler for type "%s". Must implement %s. Got: %s',
                $type,
                CastInterface::class,
                $handler
            ));
        }

        return $handler::$method($value, $params, $this->helper);
    }

    private function isValidHandler(string $handler): bool
    {
        return $this->validatedHandlers[$handler]
            ??= is_subclass_of($handler, CastInterface::class);
    }
}
