<?php

declare(strict_types=1);

namespace Project\Support;

use JsonSerializable;

/**
 * A collection of items each transformed through a Resource subclass.
 * Returned by Resource::collection().
 */
final class ResourceCollection implements JsonSerializable
{
    /** @var iterable<mixed> */
    private iterable $items;

    /** @param class-string<Resource> $resourceClass */
    public function __construct(
        private readonly string $resourceClass,
        iterable $items,
    ) {
        $this->items = $items;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->items as $item) {
            /** @var Resource $resource */
            $resource = new ($this->resourceClass)($item);
            $out[] = $resource->toArray();
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->jsonSerialize(), $flags | JSON_THROW_ON_ERROR);
    }
}
