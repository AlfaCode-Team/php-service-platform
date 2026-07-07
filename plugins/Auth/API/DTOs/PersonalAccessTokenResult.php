<?php

declare(strict_types=1);

namespace Plugins\Auth\API\DTOs;

/**
 * PersonalAccessTokenResult — the one-time result of minting a PAT: the plaintext
 * token (shown ONCE, never persisted) plus the stored record view. GDA-native
 * port of the old __DEV__ PersonalAccessTokenResult.
 */
final readonly class PersonalAccessTokenResult
{
    public function __construct(
        public string $accessToken,   // plaintext "<id>.<secret>" — shown once
        public TokenDTO $token,       // the persisted record (no secret)
    ) {}

    /** The plaintext token — send it to the client now; it is unrecoverable later. */
    public function plainTextToken(): string
    {
        return $this->accessToken;
    }

    public function id(): string
    {
        return $this->token->id;
    }

    /** @return array{token:string,id:string,abilities:list<string>} */
    public function toArray(): array
    {
        return [
            'token'     => $this->accessToken,
            'id'        => $this->token->id,
            'abilities' => $this->token->abilities,
        ];
    }
}
