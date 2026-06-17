<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

final class SecurityVerdict
{
    private function __construct(
        private readonly bool $allowed,
        private readonly int $statusCode,
        private readonly string $reason,
        private readonly ?Identity $identity,
    ) {}

    public static function allow(Request $request): self
    {
        return new self(true, 200, '', $request->identity());
    }

    public static function deny(int $statusCode, string $reason): self
    {
        return new self(false, $statusCode, $reason, null);
    }

    public function isDenied(): bool { return !$this->allowed; }
    public function isAllowed(): bool { return $this->allowed; }
    public function statusCode(): int { return $this->statusCode; }
    public function reason(): string { return $this->reason; }
    public function identity(): ?Identity { return $this->identity; }
}
