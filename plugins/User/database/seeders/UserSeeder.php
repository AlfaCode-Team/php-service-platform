<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Seeder\SeederInterface;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\Crypto\Infrastructure\PasswordHasher;

/**
 * UserSeeder — populate the `user` table with baseline data.
 *
 * Published to database/seeders/ on enable; run with `db:seed`. Uses the raw
 * driver (execute/insert/fetchAll) — no query builder, maximum speed.
 */
final class UserSeeder implements SeederInterface
{
    private readonly HashingPort $hasher;

    public function __construct(?HashingPort $hasher = null)
    {
        // Depend on the crypto.services plugin for password hashing.
        $this->hasher = $hasher ?? new PasswordHasher();
    }

    public function run(DatabaseDriverInterface $db): void
    {
        $now = date('Y-m-d H:i:s');

        $db->insert('users', [
            'user_id'       => $this->ulid(),
            'username'      => 'admin',
            'email'         => 'admin@example.com',
            'password_hash' => $this->hasher->make('password'),
            // Verified email = the login gate; set it so the admin can log in.
            'email_verified_at' => $now,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    /** Generate a 26-char Crockford ULID (fits the char(31) user_id column). */
    private function ulid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time     = (int) (microtime(true) * 1000);

        $ulid = '';
        for ($i = 9; $i >= 0; $i--) {
            $ulid = $alphabet[$time % 32] . $ulid;
            $time = intdiv($time, 32);
        }
        for ($i = 0; $i < 16; $i++) {
            $ulid .= $alphabet[random_int(0, 31)];
        }

        return $ulid;
    }

    /** @return string[] */
    public function getDependencies(): array
    {
        return [];
    }
}
