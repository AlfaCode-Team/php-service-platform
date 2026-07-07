<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;

/**
 * Authenticatable — the authenticated "current user" object a guard resolves.
 *
 * GDA-native rework of the old __DEV__ Authenticatable/AuthUserProxy contract.
 * The key difference: the kernel `Identity` remains THE security principal, so
 * this object is a rich, DB-backed view that EMITS an Identity via identity().
 * It never becomes the principal itself.
 *
 * Credential verification is NOT on this contract — the User module hides the
 * password hash by design and verifies timing-safely, so a UserProvider returns
 * an Authenticatable only AFTER a successful check (see UserProvider).
 */
interface Authenticatable
{
    /** The user's public identifier (ULID `user_id`). */
    public function getAuthIdentifier(): string;

    /** The column/attribute name of the identifier ('user_id'). */
    public function getAuthIdentifierName(): string;

    /** Display username. */
    public function getUsername(): string;

    /** Email address. */
    public function getEmail(): string;

    /**
     * Project this user into a kernel Identity — the value the SecurityGateway,
     * tenant routing and DI all key on. Roles/permissions/tenant/credential-type
     * are carried by the concrete proxy (set by whichever guard resolved it).
     */
    public function identity(): Identity;
}
