<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Gateways;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;

final class FlutterwavePaymentGateway implements PaymentGatewayContract
{
    private const INITIATE_URL = 'https://api.flutterwave.com/v3/payments';
    private const VERIFY_URL   = 'https://api.flutterwave.com/v3/transactions/%s/verify';

    public function __construct(
        private readonly string $secretKey,
        private readonly string $publicKey,
        private readonly string $logoUrl,
    ) {}

    public function initiatePayment(
        string $txRef,
        int    $amount,
        string $currency,
        string $customerEmail,
        array  $meta,
        string $redirectUrl,
        string $title,
        string $description,
    ): string {
        $payload = [
            'tx_ref'         => $txRef,
            'amount'         => $amount,
            'currency'       => $currency,
            'redirect_url'   => $redirectUrl,
            'customer'       => ['email' => $customerEmail],
            'meta'           => $meta,
            'customizations' => [
                'title'       => $title,
                'description' => $description,
                'logo'        => $this->logoUrl,
            ],
        ];

        $response = $this->post(self::INITIATE_URL, $payload);

        if (($response['status'] ?? '') !== 'success') {
            throw new GatewayException(
                'Flutterwave payment initiation failed: ' . ($response['message'] ?? 'unknown error'),
                layer:   'gateway.flutterwave.initiate',
                context: ['tx_ref' => $txRef],
            );
        }

        $link = $response['data']['link'] ?? '';
        if ($link === '') {
            throw new GatewayException(
                'Flutterwave returned no payment link.',
                layer:   'gateway.flutterwave.initiate',
                context: ['tx_ref' => $txRef],
            );
        }

        return $link;
    }

    public function verifyPayment(string $txRef): array
    {
        $url      = sprintf(self::VERIFY_URL, urlencode($txRef));
        $response = $this->get($url);

        $status = $response['data']['status'] ?? '';

        return [
            'succeeded' => $status === 'successful',
            'meta'      => $response['data']['meta'] ?? [],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function post(string $url, array $payload): array
    {
        return $this->request('POST', $url, $payload);
    }

    private function get(string $url): array
    {
        return $this->request('GET', $url, []);
    }

    /** @param array<string, mixed> $payload */
    private function request(string $method, string $url, array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new GatewayException(
                'Failed to initialise Flutterwave HTTP session.',
                layer: 'gateway.flutterwave',
            );
        }

        try {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->secretKey,
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($payload));
            }

            $raw = curl_exec($ch);

            if ($raw === false) {
                throw new GatewayException(
                    'Flutterwave request failed: ' . curl_error($ch),
                    layer: 'gateway.flutterwave',
                );
            }

            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                throw new GatewayException(
                    'Flutterwave returned non-JSON response.',
                    layer: 'gateway.flutterwave',
                );
            }

            return $decoded;
        } catch (GatewayException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new GatewayException(
                'Unexpected Flutterwave error: ' . $e->getMessage(),
                layer:    'gateway.flutterwave',
                previous: $e,
            );
        } finally {
            curl_close($ch);
        }
    }
}
