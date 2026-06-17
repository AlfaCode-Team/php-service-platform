<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Validation;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\I18n\Translator;
use Plugins\Validation\Validator;

#[CoversClass(Validator::class)]
final class ValidatorTest extends TestCase
{
    public function test_valid_data_passes_and_returns_validated_fields(): void
    {
        $validated = Validator::make(
            ['email' => 'a@b.com', 'age' => '21', 'role' => 'admin', 'extra' => 'ignored'],
            ['email' => 'required|email', 'age' => 'required|integer|min:18', 'role' => 'required|in:admin,user'],
        )->validate();

        $this->assertSame(['email' => 'a@b.com', 'age' => '21', 'role' => 'admin'], $validated);
    }

    public function test_collects_errors_per_field(): void
    {
        $errors = Validator::make(
            ['email' => 'nope', 'age' => '5'],
            ['email' => 'required|email', 'age' => 'integer|min:18'],
        )->errors();

        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('age', $errors);
    }

    public function test_validate_throws_validation_exception_with_field_errors(): void
    {
        $validator = Validator::make(['email' => 'nope'], ['email' => 'required|email']);

        try {
            $validator->validate();
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors);
        }
    }

    public function test_confirmed_rule_passes_when_matching(): void
    {
        $validator = Validator::make(
            ['password' => 'secret12', 'password_confirmation' => 'secret12'],
            ['password' => 'required|min:8|confirmed'],
        );

        $this->assertTrue($validator->passes());
    }

    public function test_confirmed_rule_fails_when_mismatched(): void
    {
        $validator = Validator::make(
            ['password' => 'secret12', 'password_confirmation' => 'different'],
            ['password' => 'required|min:8|confirmed'],
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('password', $validator->errors());
    }

    public function test_nullable_skips_rules_when_absent(): void
    {
        $validator = Validator::make([], ['nickname' => 'nullable|string|min:3']);

        $this->assertTrue($validator->passes());
    }

    public function test_between_message_interpolates_bounds(): void
    {
        $errors = Validator::make(['score' => '50'], ['score' => 'between:1,10'])->errors();

        $this->assertStringContainsString('between 1 and 10', $errors['score'][0]);
    }

    public function test_uses_translator_when_provided(): void
    {
        $translator = new Translator(\dirname(__DIR__, 4) . '/plugins/I18n/lang', 'en', 'en');
        $errors = Validator::make(['age' => '5'], ['age' => 'integer|min:18'], [], $translator)->errors();

        $this->assertSame('The age field must be at least 18.', $errors['age'][0]);
    }

    public function test_custom_message_override_wins(): void
    {
        $errors = Validator::make(
            ['email' => 'nope'],
            ['email' => 'email'],
            ['email.email' => 'Bad address.'],
        )->errors();

        $this->assertSame('Bad address.', $errors['email'][0]);
    }
}
