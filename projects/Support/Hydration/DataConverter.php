<?php

declare(strict_types=1);

namespace Project\Support\Hydration;

use Closure;
use Project\Support\Casting\DataCaster;

/**
 * PHP data  <==>  DataSource (DB) data converter / hydrator.
 *
 * This is the bridge a Repository uses to turn a raw DB row into a Domain
 * object (`reconstruct`) and a Domain object back into a column array
 * (`extract`), running every mapped field through the {@see DataCaster}.
 *
 * GDA-aligned decisions vs. the legacy HKM DataConverter:
 *  - No Active-Record `Entity` coupling and no `kernel()`/`app()` global.
 *  - Reconstruction goes through an explicit static factory name (default
 *    "reconstitute", matching the framework's Domain entity convention) or a
 *    Closure. There is NO reflection back-door that writes private properties.
 *  - Extraction goes through a method name / Closure that the object exposes
 *    (default "toRawArray"), or a plain (array) cast as a last resort.
 *
 * @template TEntity of object
 */
final class DataConverter
{
    /** @var array<string, DataCaster> */
    private static array $casterPool = [];

    private readonly DataCaster $dataCaster;

    /**
     * @param array<string, string>                            $types         [column => type]
     * @param array<string, class-string>                      $castHandlers  custom cast handlers
     * @param (Closure(array<string,mixed>): TEntity)|string   $reconstructor static factory name or closure
     * @param (Closure(TEntity): array<string,mixed>)|string   $extractor     method name or closure
     */
    public function __construct(
        private readonly array $types,
        array $castHandlers = [],
        private readonly ?object $helper = null,
        private readonly Closure|string $reconstructor = 'reconstitute',
        private readonly Closure|string $extractor = 'toRawArray',
    ) {
        $hash = md5(serialize($types) . serialize($castHandlers));
        $this->dataCaster = self::$casterPool[$hash]
            ??= new DataCaster($castHandlers, $types, $this->helper);
    }

    /**
     * DataSource row -> PHP-typed array.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function fromDataSource(array $data): array
    {
        foreach (array_intersect_key($this->types, $data) as $field => $_type) {
            $data[$field] = $this->dataCaster->castAs($data[$field], $field, 'get');
        }

        return $data;
    }

    /**
     * PHP array -> DataSource-typed array.
     *
     * @param array<string, mixed> $phpData
     * @return array<string, mixed>
     */
    public function toDataSource(array $phpData): array
    {
        foreach (array_intersect_key($this->types, $phpData) as $field => $_type) {
            $phpData[$field] = $this->dataCaster->castAs($phpData[$field], $field, 'set');
        }

        return $phpData;
    }

    /**
     * Build a Domain object from a raw DataSource row.
     *
     * @param class-string<TEntity> $classname
     * @param array<string, mixed>  $row
     * @phpstan-return TEntity
     */
    public function reconstruct(string $classname, array $row): object
    {
        $phpData = $this->fromDataSource($row);

        if ($this->reconstructor instanceof Closure) {
            return ($this->reconstructor)($phpData);
        }

        $factory = $this->reconstructor;
        if (! method_exists($classname, $factory)) {
            throw new \RuntimeException(sprintf(
                'Cannot reconstruct %s: no static factory "%s". Provide a reconstructor closure.',
                $classname,
                $factory
            ));
        }

        return $classname::$factory($phpData);
    }

    /**
     * Extract a column array from a Domain object.
     *
     * @param TEntity $object
     * @return array<string, mixed>
     */
    public function extract(object $object): array
    {
        if ($this->extractor instanceof Closure) {
            return $this->toDataSource(($this->extractor)($object));
        }

        $method = $this->extractor;
        if (method_exists($object, $method)) {
            return $this->toDataSource($object->{$method}());
        }

        // Last resort: public state only (private/protected keys are NUL-mangled and dropped).
        $row = [];
        foreach ((array) $object as $key => $value) {
            if (! str_contains($key, "\0")) {
                $row[$key] = $value;
            }
        }

        return $this->toDataSource($row);
    }
}
