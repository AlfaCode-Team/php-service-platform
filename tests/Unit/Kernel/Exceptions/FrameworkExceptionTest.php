<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\FrameworkException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrameworkException::class)]
#[CoversClass(ValidationException::class)]
final class FrameworkExceptionTest extends TestCase
{
    public function test_carries_layer_and_context(): void
    {
        $e = new ServiceException(
            'invoice.create.failed',
            layer: 'service.invoice',
            context: ['id' => 42],
        );

        self::assertSame('invoice.create.failed', $e->getMessage());
        self::assertSame('service.invoice', $e->layer);
        self::assertSame(['id' => 42], $e->context);
    }

    public function test_is_a_runtime_exception(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new ServiceException('x'));
        self::assertInstanceOf(FrameworkException::class, new RepositoryException('x'));
    }

    public function test_preserves_the_previous_throwable(): void
    {
        $root = new \PDOException('SQLSTATE[HY000]');
        $e    = new RepositoryException('find failed', layer: 'repository.invoice', previous: $root);

        self::assertSame($root, $e->getPrevious());
    }

    public function test_defaults_are_empty(): void
    {
        $e = new ServiceException('boom');

        self::assertSame('', $e->layer);
        self::assertSame([], $e->context);
    }

    public function test_validation_exception_exposes_field_errors(): void
    {
        $e = new ValidationException(['email' => 'Required.', 'age' => ['Too low.']]);

        self::assertSame('validation', $e->layer);
        self::assertArrayHasKey('email', $e->errors);
        self::assertSame('Required.', $e->errors['email']);
        self::assertSame(['Too low.'], $e->errors['age']);
    }
}
