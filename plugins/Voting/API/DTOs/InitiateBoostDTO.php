<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

final readonly class InitiateBoostDTO
{
    public function __construct(
        public string $contestantId,
        public int    $votes,         // number of votes to purchase
        public string $redirectUrl,   // where to send the user after Flutterwave
    ) {}

    public static function fromRequest(Request $request, string $contestantId): self
    {
        $votes       = (int) $request->input('votes', 0);
        $redirectUrl = trim((string) $request->input('redirect_url', ''));

        $errors = [];
        if ($votes < 1) {
            $errors['votes'] = 'votes must be at least 1.';
        } elseif ($votes > 20000) {
            $errors['votes'] = 'votes cannot exceed 20,000.';
        }
        if ($redirectUrl === '') {
            $errors['redirect_url'] = 'redirect_url is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            contestantId: $contestantId,
            votes:        $votes,
            redirectUrl:  $redirectUrl,
        );
    }
}
