<?php

declare(strict_types=1);

namespace Plugins\Authorization\Engine\Interfaces\Supports;

use Plugins\Authorization\Engine\Enforcer;


/**
 * Interface AccessPermission
 *
 * This Interface provides utility methods for managing user roles and permissions 
 * using the Casbin library. It encapsulates role assignment, role removal, 
 * permission checking, and other operations required for access control.
 */
interface AccessPermission
{

    /**
     * Set the Casbin Enforcer instance.
     *
     * This method initializes the Enforcer instance that will be used throughout
     * the trait to manage roles and permissions.
     *
     * @param Enforcer $enforcer The Casbin Enforcer instance.
     */
    public function setEnforcer(Enforcer $enforcer): void;

    /**
     * Check if the user has a specific role.
     *
     * This method checks if the current user has been assigned the specified role.
     * It uses the Casbin `hasGroupingPolicy` method to validate the policy.
     *
     * @param string $role The role to check.
     * @return bool True if the user has the role, false otherwise.
     */
    public function hasRole(string $role): bool;

    /**
     * Assign a role to the user.
     *
     * This method assigns a role to the current user using the Casbin `addRoleForUser` method.
     * If needed, existing roles can be removed before assigning a new one (commented out here).
     *
     * @param string $role The role to assign.
     * @return bool True if the role was successfully assigned, false otherwise.
     */
    public function assignRole(string $role): bool;

    /**
     * Assign one or more roles to the user.
     *
     * This method assigns one or more roles to the current user. If a single role is passed, 
     * it will be assigned as a string. If an array of roles is passed, each role will be 
     * assigned to the user.
     *
     * @param string|array $roles A single role or an array of roles to assign.
     * @return bool True if all roles were successfully assigned, false otherwise.
     */
    public function setRoles(string|array $roles): bool;


    /**
     * Remove a role from the user.
     *
     * This method removes a specific role from the current user using the Casbin `deleteRoleForUser` method.
     *
     * @param string $role The role to remove.
     * @return bool True if the role was successfully removed, false otherwise.
     */
    public function removeRole(string $role): bool;


    /**
     * Remove all roles from the user.
     *
     * This method clears all roles assigned to the current user using the Casbin `deleteRolesForUser` method.
     *
     * @return bool True if all roles were successfully removed, false otherwise.
     */
    public function removeAllRoles(): bool;

    /**
     * Check if the user is an administrator.
     *
     * This is a helper method to check if the user has the 'admin' role.
     *
     * @return bool True if the user is an admin, false otherwise.
     */
    public function isAdmin(): bool;

    /**
     * Check if the user can perform an action on a resource.
     *
     * This method checks if the user has permission to perform a specific action
     * on a given resource using the Casbin `enforce` method.
     *
     * @param string $object The resource (e.g., 'posts', 'categories').
     * @param string $action The action (e.g., 'create', 'edit', 'delete').
     * @return bool True if the user has permission, false otherwise.
     */
    public function can(string $object, string $action): bool;

    /**
     * Get all roles assigned to the user.
     *
     * This method retrieves all roles assigned to the current user using the Casbin `getRolesForUser` method.
     *
     * @return array An array of roles assigned to the user.
     */
    public function getRoles(): array;

    /**
     * Assign a permission to the user or role.
     *
     * This method assigns a specific permission (action on a resource) to the user or role.
     *
     * @param string $action The action (e.g., 'read', 'write').
     * @param string $object The resource (e.g., 'documents', 'categories').
     * @return bool True if the permission was successfully assigned, false otherwise.
     */
    public function assignPermission(string $action, string $object): bool;

    /**
     * Remove a permission from the user or role.
     *
     * This method removes a specific permission (action on a resource) from the user or role.
     *
     * @param string $action The action (e.g., 'read', 'write').
     * @param string $object The resource (e.g., 'documents', 'categories').
     * @return bool True if the permission was successfully removed, false otherwise.
     */
    public function removePermission(string $action, string $object): bool;

    /**
     * Get all permissions assigned to the user.
     *
     * @return array
     */
    public function getPermissions(): array;

    // =========================
    // Enterprise Helper Methods
    // =========================
    public function canCheckout(): bool ;
    public function canRefund(): bool;
    public function canApplyDiscount(): bool;
    public function canIssueGiftCard(): bool;
    public function canRedeemGiftCard(): bool;
    public function canAdjustInventory(): bool;
    public function canTransferStock(): bool;
    public function canCountInventory(): bool;
    public function canManageProducts(): bool;
    public function canManageCustomers(): bool;
    public function canManageSuppliers(): bool;
    public function canManageOrders(): bool;
    public function canViewReports(): bool;
    public function canGenerateReports(): bool;
    public function canOpenPOS(): bool;
    public function canClosePOS(): bool;

    // =========================
    // Settings Access Control
    // =========================
    public function canReadSettings(): bool;
    public function canUpdateSettings(): bool;
    public function canConfigureTax(): bool;
    public function canManageBranches(): bool;
}
