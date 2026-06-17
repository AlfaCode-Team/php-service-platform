<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Support\Arr;
use Plugins\Support\Collection;
use Plugins\Support\Str;

#[CoversClass(Collection::class)]
#[CoversClass(Arr::class)]
#[CoversClass(Str::class)]
final class CollectionTest extends TestCase
{
    public function test_map_then_sort_then_values(): void
    {
        $out = (new Collection([3, 1, 2]))->map(static fn(int $n) => $n * 2)->sort()->values()->all();

        $this->assertSame([2, 4, 6], $out);
    }

    public function test_sort_by_key(): void
    {
        $people = new Collection([['name' => 'a', 'age' => 30], ['name' => 'b', 'age' => 20]]);

        $this->assertSame('b', $people->sortBy('age')->first()['name']);
    }

    public function test_pluck_and_sum_and_where(): void
    {
        $people = new Collection([['name' => 'a', 'age' => 30], ['name' => 'b', 'age' => 20]]);

        $this->assertSame(['a', 'b'], $people->pluck('name')->all());
        $this->assertSame(50, $people->sum('age'));
        $this->assertSame(1, $people->where('age', 20)->count());
    }

    public function test_flatten_and_to_json(): void
    {
        $this->assertSame([1, 2, 3], (new Collection([1, [2, [3]]]))->flatten()->all());
        $this->assertSame('[1,2,3]', (new Collection([1, 2, 3]))->toJson());
    }

    public function test_group_by(): void
    {
        $grouped = (new Collection([
            ['team' => 'x', 'n' => 1],
            ['team' => 'y', 'n' => 2],
            ['team' => 'x', 'n' => 3],
        ]))->groupBy('team');

        $this->assertSame(2, $grouped->get('x')->count());
    }

    public function test_array_helpers(): void
    {
        $this->assertSame(1, Arr::get(['a' => ['b' => 1]], 'a.b'));
        $this->assertSame(['a' => 1], Arr::only(['a' => 1, 'b' => 2], ['a']));
    }

    public function test_string_helpers(): void
    {
        $this->assertSame('hello_world', Str::snake('HelloWorld'));
        $this->assertSame('HelloWorld', Str::studly('hello_world'));
        $this->assertSame('hello-world', Str::kebab('helloWorld'));
    }
}
