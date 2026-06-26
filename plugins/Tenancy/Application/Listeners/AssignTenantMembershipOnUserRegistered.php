<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Listeners;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use Plugins\Tenancy\Application\Ports\MembershipWriter;

/**
 * Assigns a freshly-registered user to their originating tenant.
 *
 * Subscribed to `user.registered` and driven by the User outbox relay — so it
 * runs out-of-band (CoreContainer-resolved, no request context). The tenant
 * therefore travels ON the event payload (`tenantId`), set from the request's
 * resolved tenant at self-signup time. No tenant on the event (central/apex
 * signup) → nothing to assign; new members default to the `member` role.
 *
 * Idempotent: upsertActive is a portable upsert keyed on (user_id, tenant_id),
 * so an at-least-once relay redelivery cannot create duplicate seats.
 */
final class AssignTenantMembershipOnUserRegistered implements EventListenerContract
{
    private const DEFAULT_ROLE = 'member';

    public function __construct(
        private readonly MembershipWriter $memberships,
    ) {}

    public function handle(IntegrationEventContract $event): void
    {
        $payload  = $event->payload();
        $userId   = (string) ($payload['userId'] ?? '');
        $tenantId = (string) ($payload['tenantId'] ?? '');

        if ($userId === '' || $tenantId === '') {
            return; // no tenant context on this registration — nothing to assign
        }

        $this->memberships->upsertActive($userId, $tenantId, self::DEFAULT_ROLE);
    }
}
