<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Database\Infrastructure\Persistence\ConnectionManager;
use Plugins\Database\Exceptions\ConnectionException;

#[CoversClass(ConnectionManager::class)]
final class ConnectionManagerTest extends TestCase
{
    public function test_resolves_registered_connection(): void
    {
        $manager = new ConnectionManager();
        $manager->register('default', new SQLiteConfiguration(':memory:'));

        $this->assertInstanceOf(DatabasePort::class, $manager->connection('default'));
    }

    public function test_default_uses_configured_default_name(): void
    {
        $manager = new ConnectionManager(defaultName: 'primary');
        $manager->register('primary', new SQLiteConfiguration(':memory:'));

        $this->assertInstanceOf(DatabasePort::class, $manager->default());
    }

    public function test_same_instance_returned_for_repeat_resolution(): void
    {
        $manager = new ConnectionManager();
        $manager->register('default', new SQLiteConfiguration(':memory:'));

        $this->assertSame($manager->connection('default'), $manager->connection('default'));
    }

    public function test_unknown_connection_throws(): void
    {
        $manager = new ConnectionManager();

        $this->expectException(ConnectionException::class);
        $manager->connection('missing');
    }

    public function test_has_reports_registration_state(): void
    {
        $manager = new ConnectionManager();

        $this->assertFalse($manager->has('replica'));
        $manager->register('replica', new SQLiteConfiguration(':memory:'));
        $this->assertTrue($manager->has('replica'));
    }

    public function test_lists_all_connection_names(): void
    {
        $manager = new ConnectionManager();
        $manager->register('default', new SQLiteConfiguration(':memory:'));
        $manager->register('replica', new SQLiteConfiguration(':memory:'));

        $this->assertSame(['default', 'replica'], $manager->connections());
    }

    public function test_supports_multiple_independent_connections(): void
    {
        $manager = new ConnectionManager();
        $manager->register('a', new SQLiteConfiguration(':memory:'));
        $manager->register('b', new SQLiteConfiguration(':memory:'));

        $this->assertNotSame($manager->connection('a'), $manager->connection('b'));
    }

    public function test_close_forces_new_instance_on_next_resolution(): void
    {
        $manager = new ConnectionManager();
        $manager->register('default', new SQLiteConfiguration(':memory:'));

        $first = $manager->connection('default');
        $manager->close('default');
        $second = $manager->connection('default');

        $this->assertNotSame($first, $second);
    }

    public function test_close_all_drops_every_resolved_connection(): void
    {
        $manager = new ConnectionManager();
        $manager->register('default', new SQLiteConfiguration(':memory:'));

        $first = $manager->connection('default');
        $manager->closeAll();

        $this->assertNotSame($first, $manager->connection('default'));
        // Config registration survives closeAll.
        $this->assertTrue($manager->has('default'));
    }

    public function test_re_registering_replaces_resolved_connection(): void
    {
        $manager = new ConnectionManager();
        $manager->register('default', new SQLiteConfiguration(':memory:'));
        $first = $manager->connection('default');

        $manager->register('default', new SQLiteConfiguration(':memory:'));
        $this->assertNotSame($first, $manager->connection('default'));
    }
}
