<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Seeder\Factory\EntityFactory;
use AlfaCode\LetMigrate\Seeder\Factory\FakeData;
use Plugins\Crypto\Infrastructure\PasswordHasher;

/**
 * UserFactory — generate fake `user` rows for seeders / tests.
 *
 * Published to database/factories/ on enable.
 */
// Depend on the crypto.services plugin for password hashing.
$hasher = new PasswordHasher();

return EntityFactory::for('users')
    ->definition(function (FakeData $f, int $i) use ($hasher) {
        $now = date('Y-m-d H:i:s');

        return [
            'user_id'       => substr(str_replace('-', '', $f->uuid()), 0, 31),
            'username'      => 'user' . ($i + 1),
            'email'         => $f->uniqueEmail($i),
            'password_hash' => $hasher->make('password'),
            // Verified email = the login gate; set it so factory users can log in.
            'email_verified_at' => $now,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];
    })
    ->locale('en_US')
    ->count(10);
