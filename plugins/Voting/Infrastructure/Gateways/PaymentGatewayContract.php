<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Gateways;

interface PaymentGatewayContract
{
    /**
     * Initiates a payment session and returns a hosted checkout link.
     *
     * @param array<string, mixed> $meta  Arbitrary payload stored in TransactionMeta and echoed back on confirmation.
     */
    public function initiatePayment(
        string $txRef,
        int    $amount,
        string $currency,
        string $customerEmail,
        array  $meta,
        string $redirectUrl,
        string $title,
        string $description,
    ): string; // payment link URL

    /**
     * Verifies a transaction by its reference and returns the stored meta payload.
     *
     * @return array{succeeded: bool, meta: array<string, mixed>}
     */
    public function verifyPayment(string $txRef): array;
}
