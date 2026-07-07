<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
final class ResponseTest extends TestCase
{
    public function test_json_sets_status_and_encodes_body(): void
    {
        $res = Response::json(['ok' => true, 'n' => 3], 200);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['ok' => true, 'n' => 3], json_decode($res->getContent(), true));
    }

    public function test_created_is_201(): void
    {
        self::assertSame(201, Response::created(['id' => 1])->getStatusCode());
    }

    public function test_no_content_is_204(): void
    {
        self::assertSame(204, Response::noContent()->getStatusCode());
        self::assertSame(204, Response::empty()->getStatusCode());
    }

    /**
     * @return array<string, array{0: callable(): Response, 1: int, 2: string}>
     */
    public static function errorProvider(): array
    {
        return [
            'notFound'     => [fn() => Response::notFound(),     404, 'not_found'],
            'unauthorized' => [fn() => Response::unauthorized(), 401, 'unauthorized'],
            'forbidden'    => [fn() => Response::forbidden(),    403, 'forbidden'],
        ];
    }

    /**
     * @param callable(): Response $factory
     * @dataProvider errorProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('errorProvider')]
    public function test_error_envelopes(callable $factory, int $status, string $code): void
    {
        $res  = $factory();
        $body = json_decode($res->getContent(), true);

        self::assertSame($status, $res->getStatusCode());
        self::assertArrayHasKey('error', $body);
        self::assertSame($code, $body['error']['code']);
    }

    public function test_unprocessable_carries_field_errors(): void
    {
        $res  = Response::unprocessable(['email' => 'Required.']);
        $body = json_decode($res->getContent(), true);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame('validation_failed', $body['error']['code']);
        self::assertSame(['email' => 'Required.'], $body['error']['fields']);
    }

    public function test_redirect_defaults_to_302_with_location(): void
    {
        $res = Response::redirect('/login');

        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/login', $res->headers->get('Location'));
    }
}
