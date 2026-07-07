<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Loading;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\CircularDependencyException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Loading\DependencyGraphCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencyGraphCalculator::class)]
final class DependencyGraphCalculatorTest extends TestCase
{
    /** @param array<string, list<string>> $edges */
    private function calc(array $edges): DependencyGraphCalculator
    {
        $services = [];
        foreach ($edges as $name => $requires) {
            $services[$name] = ['requires' => $requires];
        }
        return new DependencyGraphCalculator(['services' => $services]);
    }

    public function test_resolves_a_single_service_with_no_deps(): void
    {
        $graph = $this->calc(['a' => []])->resolve('a');

        self::assertSame(['a'], $graph->moduleNames());
    }

    public function test_dependencies_come_before_the_dependent(): void
    {
        // c -> b -> a : a and b must be present before c.
        $graph = $this->calc(['a' => [], 'b' => ['a'], 'c' => ['b']])->resolve('c');

        $names = $graph->moduleNames();
        self::assertContains('a', $names);
        self::assertContains('b', $names);
        self::assertContains('c', $names);
        self::assertLessThan(array_search('c', $names, true), array_search('b', $names, true));
        self::assertLessThan(array_search('b', $names, true), array_search('a', $names, true));
    }

    public function test_shared_dependency_is_resolved_once(): void
    {
        // b and c both require a; a appears exactly once.
        $graph = $this->calc(['a' => [], 'b' => ['a'], 'c' => ['a'], 'd' => ['b', 'c']])->resolve('d');

        $names = $graph->moduleNames();
        self::assertSame(1, count(array_keys($names, 'a', true)));
    }

    public function test_additional_domains_are_seeded_into_the_graph(): void
    {
        $graph = $this->calc(['page' => [], 'view' => [], 'tpl' => ['view']])
            ->resolve('page', ['tpl']);

        $names = $graph->moduleNames();
        self::assertContains('page', $names);
        self::assertContains('tpl', $names);
        self::assertContains('view', $names); // transitive of the additional
    }

    public function test_circular_dependency_throws(): void
    {
        $this->expectException(CircularDependencyException::class);
        $this->calc(['a' => ['b'], 'b' => ['a']])->resolve('a');
    }

    public function test_missing_service_throws_kernel_exception(): void
    {
        $this->expectException(KernelException::class);
        $this->calc(['a' => []])->resolve('does-not-exist');
    }

    public function test_entry_returns_the_manifest_row(): void
    {
        $graph = $this->calc(['a' => [], 'b' => ['a']])->resolve('b');

        self::assertSame(['requires' => ['a']], $graph->entry('b'));
        self::assertSame([], $graph->entry('unknown'));
    }
}
