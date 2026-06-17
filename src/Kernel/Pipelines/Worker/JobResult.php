<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker;

final class JobResult
{
    private function __construct(
        private readonly bool $success,
        private readonly bool $skipped,
        private readonly string $skipReason,
        private readonly array $data,
    ) {}

    public static function success(array $data = []): self
    {
        return new self(true, false, '', $data);
    }

    public static function skipped(string $reason): self
    {
        return new self(true, true, $reason, []);
    }

    public function isSuccess(): bool { return $this->success; }
    public function isSkipped(): bool { return $this->skipped; }
    public function skipReason(): string { return $this->skipReason; }
    public function data(): array { return $this->data; }
}
