<?php

declare(strict_types=1);

namespace Plugins\Commands\Secrets;

final class SecretsManager
{
    private static ?SecretsVault $vault = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::$vault ??= self::createVault();

        try {
            return self::$vault->retrieve($key);
        } catch (SecretNotFoundException) {
            if ($default === null) {
                throw new SecretNotFoundException("Secret not found: {$key}");
            }
            return $default;
        }
    }

    public static function has(string $key): bool
    {
        self::$vault ??= self::createVault();

        try {
            self::$vault->retrieve($key);
            return true;
        } catch (SecretNotFoundException) {
            return false;
        }
    }

    private static function createVault(): SecretsVault
    {
        $provider = (string) (env('SECRETS_PROVIDER') ?: 'env');

        return match ($provider) {
            'aws'   => new AwsSecretsManagerVault(),
            'vault' => new HashiCorpVaultAdapter(),
            'env'   => new EnvironmentVariableVault(),
            default => new EnvironmentVariableVault(),  // Default to env vars
        };
    }
}

interface SecretsVault
{
    public function retrieve(string $key): string;
}

final class EnvironmentVariableVault implements SecretsVault
{
    public function retrieve(string $key): string
    {
        $value = env($key);

        if ($value === false) {
            throw new SecretNotFoundException("Environment variable not found: {$key}");
        }

        return (string) $value;
    }
}

final class AwsSecretsManagerVault implements SecretsVault
{
    private $client;

    public function __construct()
    {
        if (!class_exists('Aws\SecretsManager\SecretsManagerClient')) {
            throw new SecretNotFoundException(
                'AWS SDK not installed. Install via: composer require aws/aws-sdk-php'
            );
        }

        $this->client = new \Aws\SecretsManager\SecretsManagerClient([
            'version' => 'latest',
            'region'  => env('AWS_REGION') ?: 'us-east-1',
        ]);
    }

    public function retrieve(string $key): string
    {
        try {
            $result = $this->client->getSecretValue(['SecretId' => $key]);

            if (isset($result['SecretString'])) {
                return $result['SecretString'];
            }

            if (isset($result['SecretBinary'])) {
                return base64_decode($result['SecretBinary']);
            }

            throw new SecretNotFoundException("Secret has no value: {$key}");
        } catch (\Exception $e) {
            throw new SecretNotFoundException("Failed to retrieve secret: {$e->getMessage()}");
        }
    }
}

final class HashiCorpVaultAdapter implements SecretsVault
{
    private string $address;
    private string $token;

    public function __construct()
    {
        $this->address = env('VAULT_ADDR') ?: 'http://127.0.0.1:8200';
        $this->token = env('VAULT_TOKEN') ?: '';

        if (!$this->token) {
            throw new SecretNotFoundException('VAULT_TOKEN environment variable not set');
        }
    }

    public function retrieve(string $key): string
    {
        $url = $this->address . '/v1/secret/data/' . $key;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Vault-Token: ' . $this->token,
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new SecretNotFoundException("Vault returned {$httpCode} for key: {$key}");
        }

        $data = json_decode($response, true);
        $value = $data['data']['data']['value'] ?? null;

        if ($value === null) {
            throw new SecretNotFoundException("Secret not found in Vault: {$key}");
        }

        return $value;
    }
}

final class SecretNotFoundException extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
