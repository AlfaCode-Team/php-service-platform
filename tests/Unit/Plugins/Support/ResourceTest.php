<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Support\Resource;
use Plugins\Support\ResourceCollection;

#[CoversClass(Resource::class)]
#[CoversClass(ResourceCollection::class)]
final class ResourceTest extends TestCase
{
    public function test_transforms_an_array_source(): void
    {
        $resource = new UserTestResource(['id' => 1, 'name' => 'Ann', 'email' => 'a@b.com']);

        $this->assertSame(['id' => 1, 'name' => 'Ann', 'email' => 'a@b.com'], $resource->toArray());
    }

    public function test_reads_object_property_and_getter_method(): void
    {
        $source = new class {
            public string $name = 'Bob';
            public function id(): int { return 9; }
            public function email(): string { return 'b@c.com'; }
        };

        $this->assertSame(['id' => 9, 'name' => 'Bob', 'email' => 'b@c.com'], (new UserTestResource($source))->toArray());
    }

    public function test_collection_maps_each_item(): void
    {
        $collection = UserTestResource::collection([
            ['id' => 1, 'name' => 'x', 'email' => 'x@y'],
            ['id' => 2, 'name' => 'y', 'email' => 'y@z'],
        ]);

        $this->assertInstanceOf(ResourceCollection::class, $collection);
        $this->assertCount(2, $collection->toArray());
        $this->assertSame(2, $collection->toArray()[1]['id']);
    }

    public function test_json_serialization(): void
    {
        $collection = UserTestResource::collection([['id' => 1, 'name' => 'x', 'email' => 'x@y']]);

        $this->assertSame('x', json_decode($collection->toJson(), true)[0]['name']);
    }
}

/** Test-only resource shape. */
final class UserTestResource extends Resource
{
    public function toArray(): array
    {
        return [
            'id'    => $this->get('id'),
            'name'  => $this->get('name'),
            'email' => $this->get('email'),
        ];
    }
}
