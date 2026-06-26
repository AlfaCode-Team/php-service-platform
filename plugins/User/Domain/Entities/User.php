<?php

declare(strict_types=1);

namespace Plugins\User\Domain\Entities;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use Plugins\User\Domain\Events\UserDeletedDomainEvent;
use Plugins\User\Domain\Events\UserRegisteredDomainEvent;
use Plugins\User\Domain\Events\UserUpdatedDomainEvent;
use Plugins\User\Domain\ValueObjects\Email;
use Plugins\User\Domain\ValueObjects\UserId;
use Plugins\User\Domain\ValueObjects\Username;
use Plugins\User\Domain\ValueObjects\UserStatus;

/**
 * User aggregate — mirrors the `users` table.
 *
 * The Domain layer stays pure: it never hashes a password itself (that needs
 * the crypto.services HashingPort). The Application service hashes the plaintext
 * and passes the resulting bcrypt string in; this entity only ever holds the
 * already-hashed value, and that value is never exposed back out.
 */
final class User
{
    /** @var list<DomainEventContract> */
    private array $domainEvents = [];

    /** Field names mutated since the last persistence — drives the update event. */
    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        private readonly UserId $id,
        private Username $username,
        private Email $email,
        private string $passwordHash,
        private UserStatus $status,
        private ?string $rememberToken,
        private int $version,
        private ?\DateTimeImmutable $emailVerifiedAt,
        private readonly \DateTimeImmutable $createdAt,
    ) {}

    /**
     * Register a brand-new user. $passwordHash MUST already be a bcrypt hash
     * produced by the HashingPort — never a plaintext password.
     */
    public static function register(
        Username $username,
        Email $email,
        string $passwordHash,
        UserStatus $status = UserStatus::Pending,
    ): self {
        self::assertBcrypt($passwordHash, 'User must be created with a bcrypt password hash.');

        $user = new self(
            id:              UserId::generate(),
            username:        $username,
            email:           $email,
            passwordHash:    $passwordHash,
            status:          $status,
            rememberToken:   null,
            version:         1,
            emailVerifiedAt: null,
            createdAt:       new \DateTimeImmutable(),
        );

        $user->domainEvents[] = new UserRegisteredDomainEvent(
            userId:     $user->id,
            username:   $user->username,
            email:      $user->email,
            occurredAt: $user->createdAt,
        );

        return $user;
    }

    /** Rehydrate from persistence — records NO events. */
    public static function reconstitute(
        string $id,
        string $username,
        string $email,
        string $passwordHash,
        int $status,
        ?string $rememberToken,
        int $version,
        ?string $emailVerifiedAt,
        string $createdAt,
    ): self {
        return new self(
            id:              UserId::fromString($id),
            username:        Username::fromString($username),
            email:           Email::fromString($email),
            passwordHash:    $passwordHash,
            status:          UserStatus::from($status),
            rememberToken:   $rememberToken,
            version:         $version,
            emailVerifiedAt: $emailVerifiedAt !== null ? new \DateTimeImmutable($emailVerifiedAt) : null,
            createdAt:       new \DateTimeImmutable($createdAt),
        );
    }

    public function changeEmail(Email $email): void
    {
        if ($email->value() === $this->email->value()) {
            return;
        }
        $this->email = $email;
        // A new address is unverified until reconfirmed.
        $this->emailVerifiedAt = null;
        $this->markChanged('email');
    }

    public function rename(Username $username): void
    {
        if ($username->value() === $this->username->value()) {
            return;
        }
        $this->username = $username;
        $this->markChanged('username');
    }

    /** Replace the stored credential with a new bcrypt hash. */
    public function changePassword(string $passwordHash): void
    {
        self::assertBcrypt($passwordHash, 'Password must be a bcrypt hash.');
        $this->passwordHash = $passwordHash;
        // Any "remember me" sessions are invalidated on credential change.
        $this->rememberToken = null;
        $this->markChanged('password');
    }

    /** Mark the email confirmed; a pending account becomes active. */
    public function verifyEmail(): void
    {
        if ($this->emailVerifiedAt !== null) {
            return;
        }
        $this->emailVerifiedAt = new \DateTimeImmutable();
        if ($this->status === UserStatus::Pending) {
            $this->status = UserStatus::Active;
        }
        $this->markChanged('email_verified');
    }

    public function activate(): void
    {
        if (!$this->status->isActive()) {
            $this->status = UserStatus::Active;
            $this->markChanged('status');
        }
    }

    public function deactivate(): void
    {
        if ($this->status !== UserStatus::Inactive) {
            $this->status = UserStatus::Inactive;
            $this->markChanged('status');
        }
    }

    /**
     * Record a single consolidated update event for the fields mutated so far.
     * Bumps the optimistic-lock version. Returns true when something actually
     * changed. Call after applying edits, before persisting.
     */
    public function commitChanges(): bool
    {
        if ($this->changed === []) {
            return false;
        }

        $this->version++;

        $this->domainEvents[] = new UserUpdatedDomainEvent(
            userId:     $this->id,
            changed:    array_values(array_unique($this->changed)),
            occurredAt: new \DateTimeImmutable(),
        );
        $this->changed = [];

        return true;
    }

    /** Record the (soft-)deletion event. */
    public function markDeleted(): void
    {
        $this->domainEvents[] = new UserDeletedDomainEvent(
            userId:     $this->id,
            occurredAt: new \DateTimeImmutable(),
        );
    }

    private function markChanged(string $field): void
    {
        $this->changed[] = $field;
    }

    /** Store the SHA-256 of a "remember me" token (never the raw token). */
    public function setRememberTokenHash(?string $sha256Hash): void
    {
        $this->rememberToken = $sha256Hash;
    }

    /** @return list<DomainEventContract> */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }

    private static function assertBcrypt(string $hash, string $message): void
    {
        // bcrypt output is always exactly 60 chars and starts with $2y$/$2a$/$2b$.
        if (strlen($hash) !== 60 || !str_starts_with($hash, '$2')) {
            throw new \DomainException($message);
        }
    }

    public function id(): UserId                       { return $this->id; }
    public function username(): Username               { return $this->username; }
    public function email(): Email                     { return $this->email; }
    public function status(): UserStatus               { return $this->status; }
    public function version(): int                     { return $this->version; }
    public function createdAt(): \DateTimeImmutable    { return $this->createdAt; }
    public function emailVerifiedAt(): ?\DateTimeImmutable { return $this->emailVerifiedAt; }
    public function isEmailVerified(): bool            { return $this->emailVerifiedAt !== null; }

    /** Persistence-only accessors — never serialise these into a response. */
    public function passwordHash(): string   { return $this->passwordHash; }
    public function rememberToken(): ?string { return $this->rememberToken; }
}
