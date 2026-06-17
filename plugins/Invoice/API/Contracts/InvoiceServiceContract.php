<?php

declare(strict_types=1);

namespace Plugins\Invoice\API\Contracts;

interface InvoiceServiceContract
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array;

    /** @return array<string,mixed> */
    public function find(string $id): array;
}
