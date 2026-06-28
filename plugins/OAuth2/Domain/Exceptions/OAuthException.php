<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Domain\Exceptions;

/**
 * OAuthException — an RFC 6749 §5.2 / §4.1.2.1 error.
 *
 * Carries the standard `error` code, a human description, an HTTP status, and
 * (for redirect-based errors) whether it should be returned to the client's
 * redirect URI rather than rendered directly.
 */
final class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description = '',
        public readonly int $status = 400,
        public readonly bool $redirectable = false,
    ) {
        parent::__construct($description !== '' ? $description : $error);
    }

    public static function invalidRequest(string $description = 'The request is missing a parameter or is malformed.'): self
    {
        return new self('invalid_request', $description, 400);
    }

    public static function invalidClient(string $description = 'Client authentication failed.'): self
    {
        return new self('invalid_client', $description, 401);
    }

    public static function invalidGrant(string $description = 'The provided grant is invalid, expired, or revoked.'): self
    {
        return new self('invalid_grant', $description, 400);
    }

    public static function unauthorizedClient(string $description = 'The client is not authorized to use this grant.'): self
    {
        return new self('unauthorized_client', $description, 400);
    }

    public static function unsupportedGrantType(string $description = 'The grant type is not supported.'): self
    {
        return new self('unsupported_grant_type', $description, 400);
    }

    public static function invalidScope(string $description = 'The requested scope is invalid or unknown.'): self
    {
        return new self('invalid_scope', $description, 400);
    }

    public static function accessDenied(string $description = 'The resource owner denied the request.'): self
    {
        return new self('access_denied', $description, 400, true);
    }

    public static function unsupportedResponseType(string $description = 'The response type is not supported.'): self
    {
        return new self('unsupported_response_type', $description, 400, true);
    }

    /** @return array{error:string,error_description:string} */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
