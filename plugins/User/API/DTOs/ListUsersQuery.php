<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

/**
 * Keyset-pagination query for listing users.
 *
 *   GET /api/users?limit=50&after=<last user_id seen>
 *
 * Keyset (cursor) pagination is O(1) at any depth — unlike OFFSET, which scans
 * and skips. `after` is the opaque user_id of the last row from the previous
 * page (results are ordered by user_id DESC, which is time-ordered via ULID).
 */
final readonly class ListUsersQuery
{
    public const DEFAULT_LIMIT = 25;
    public const MAX_LIMIT     = 100;

    public function __construct(
        public int $limit,
        public ?string $after,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $limit = (int) $request->input('limit', self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $after = trim((string) $request->input('after', ''));
        // user_id is Crockford base32 ULID — reject anything else as a cursor.
        if ($after === '' || !preg_match('/^[0-9A-HJKMNP-TV-Z]{1,31}$/', $after)) {
            $after = null;
        }

        return new self(limit: $limit, after: $after);
    }
}
