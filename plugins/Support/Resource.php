<?php

declare(strict_types=1);

namespace Plugins\Support;

use JsonSerializable;

/**
 * Base API resource (transformer): maps a domain object/array into the exact
 * shape an API returns, keeping that mapping out of controllers and services.
 *
 * Define a subclass per output shape:
 *
 *   final class UserResource extends Resource {
 *       public function toArray(): array {
 *           return [
 *               'id'    => $this->get('id'),
 *               'name'  => $this->get('name'),
 *               'email' => $this->get('email'),
 *           ];
 *       }
 *   }
 *
 *   UserResource::make($user)->toArray();
 *   UserResource::collection($users)->toArray();   // list of shaped items
 *
 * @template T
 */
abstract class Resource implements JsonSerializable
{
    /** @param T $resource */
    public function __construct(
        protected readonly mixed $resource,
    ) {
    }

    /**
     * Transform the wrapped resource into an array.
     *
     * @return array<string,mixed>
     */
    abstract public function toArray(): array;

    /** @param T $resource */
    public static function make(mixed $resource): static
    {
        return new static($resource);
    }

    /**
     * Wrap a list of resources, each transformed by this resource class.
     *
     * @param iterable<mixed> $resources
     */
    public static function collection(iterable $resources): ResourceCollection
    {
        return new ResourceCollection(static::class, $resources);
    }

    /**
     * Read a field from the wrapped resource whether it's an array, an object
     * with a public property, or an object exposing a getter method.
     */
    protected function get(string $key, mixed $default = null): mixed
    {
        $r = $this->resource;

        if (is_array($r)) {
            return $r[$key] ?? $default;
        }
        if (is_object($r)) {
            if (isset($r->{$key})) {
                return $r->{$key};
            }
            if (method_exists($r, $key)) {
                return $r->{$key}();
            }
        }
        return $default;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->jsonSerialize(), $flags | JSON_THROW_ON_ERROR);
    }
}
