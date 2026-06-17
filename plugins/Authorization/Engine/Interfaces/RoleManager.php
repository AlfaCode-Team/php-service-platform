<?php

declare(strict_types=1);

namespace Plugins\Authorization\Engine\Interfaces;

use Closure;
use Plugins\Authorization\Engine\Interfaces\Logger;

/**
 * Interface RoleManager.
 * Defines operations for managing roles, role inheritance, and role-user relationships.
 * This interface provides methods for adding, removing, and checking role inheritance, 
 * along with support for domain-based role management.
 * It also allows the addition of custom matching functions for roles and domains.
 *
 * @package Plugins\Authorization\Engine
 * @author Hakeem Rasheed <hakimushamavu@gmail.com>
 */
interface RoleManager
{
    const DEFAULT_DOMAIN = ''; // Default domain to be used when no domain is provided.

    /**
     * Clears all stored data and resets the role manager to its initial state.
     * This is typically used when you need to reset the role manager, e.g., for testing or reconfiguration.
     * 
     * Example usage:
     * $roleManager->clear(); // Clears all data and resets the state.
     *
     * @return void
     */
    public function clear(): void;

    /**
     * Adds an inheritance link between two roles, where `name1` inherits `name2`.
     * Optionally, you can specify one or more domains that will act as prefixes for the roles.
     * 
     * This allows you to create role hierarchies (e.g., "admin" inherits "user").
     *
     * Example usage:
     * $roleManager->addLink("admin", "user"); // "admin" inherits "user".
     * $roleManager->addLink("admin", "user", "sales"); // "admin" inherits "user" in the "sales" domain.
     *
     * @param string $name1 The role that will inherit (e.g., "admin").
     * @param string $name2 The role being inherited (e.g., "user").
     * @param string ...$domain Optional domains that act as prefixes for roles (e.g., "sales").
     * 
     * @return void
     */
    public function addLink(string $name1, string $name2, string ...$domain): void;

    /**
     * Deletes the inheritance link between two roles, where `name1` no longer inherits `name2`.
     * Optionally, you can specify one or more domains to target specific role inheritance links.
     * 
     * Example usage:
     * $roleManager->deleteLink("admin", "user"); // "admin" no longer inherits "user".
     * $roleManager->deleteLink("admin", "user", "sales"); // "admin" no longer inherits "user" in the "sales" domain.
     *
     * @param string $name1 The role that will no longer inherit (e.g., "admin").
     * @param string $name2 The role that is no longer inherited (e.g., "user").
     * @param string ...$domain Optional domains that act as prefixes for roles (e.g., "sales").
     * 
     * @return void
     */
    public function deleteLink(string $name1, string $name2, string ...$domain): void;

    /**
     * Determines whether role `name1` inherits role `name2`.
     * This operation supports domains to check inheritance in a domain-specific context.
     * 
     * Example usage:
     * $isInherited = $roleManager->hasLink("admin", "user"); // Returns true if "admin" inherits "user".
     * $isInherited = $roleManager->hasLink("admin", "user", "sales"); // Returns true if "admin" inherits "user" in the "sales" domain.
     *
     * @param string $name1 The role to check for inheritance (e.g., "admin").
     * @param string $name2 The role being checked for inheritance (e.g., "user").
     * @param string ...$domain Optional domains to check against.
     *
     * @return bool Returns true if `name1` inherits `name2`, otherwise false.
     */
    public function hasLink(string $name1, string $name2, string ...$domain): bool;

    /**
     * Gets all the roles that a subject (e.g., user or group) `name` inherits.
     * Optionally, you can specify domains to get domain-specific inherited roles.
     * 
     * Example usage:
     * $roles = $roleManager->getRoles("admin"); // Returns all roles inherited by "admin".
     * $roles = $roleManager->getRoles("admin", "sales"); // Returns roles inherited by "admin" in the "sales" domain.
     *
     * @param string $name The subject whose inherited roles are being fetched.
     * @param string ...$domain Optional domains to consider when fetching roles.
     *
     * @return string[] An array of roles inherited by the subject.
     */
    public function getRoles(string $name, string ...$domain): array;

    /**
     * Gets all the users that inherit the subject `name`.
     * This is useful for finding which users have a certain role or permission.
     * 
     * Example usage:
     * $users = $roleManager->getUsers("admin"); // Returns all users who inherit "admin".
     * $users = $roleManager->getUsers("admin", "sales"); // Returns users who inherit "admin" in the "sales" domain.
     *
     * @param string $name The subject whose inheritors are being fetched.
     * @param string ...$domain Optional domains to consider when fetching users.
     *
     * @return string[] An array of users who inherit the subject.
     */
    public function getUsers(string $name, string ...$domain): array;

    /**
     * Prints all roles to the log for auditing or debugging purposes.
     * This can be used to review the current role setup, useful for debugging or system auditing.
     * 
     * Example usage:
     * $roleManager->printRoles(); // Logs all roles for review.
     *
     * @return void
     */
    public function printRoles(): void;

    /**
     * Gets the domains that a subject `name` inherits.
     * This is useful for understanding the scope or context in which a subject's roles apply.
     * 
     * Example usage:
     * $domains = $roleManager->getDomains("admin"); // Returns all domains where "admin" has roles.
     *
     * @param string $name The subject whose inherited domains are being fetched.
     *
     * @return string[] An array of domains inherited by the subject.
     */
    public function getDomains(string $name): array;

    /**
     * Sets the current logger.
     *
     * @param Logger $logger
     *
     * @return void
     */
    public function setLogger(Logger $logger): void;

    /**
     * Gets all available domains in the system.
     * This method returns a list of all domains that are in use, which could represent different contexts or areas.
     * 
     * Example usage:
     * $domains = $roleManager->getAllDomains(); // Returns all available domains.
     *
     * @return string[] An array of all domains.
     */
    public function getAllDomains(): array;

    /**
     * Adds a custom matching function for a role. 
     * This function allows you to perform more complex or customized matches for roles based on custom logic.
     * 
     * Example usage:
     * $roleManager->addMatchingFunc("admin", function($role) {
     *     return strpos($role, "admin") === 0; // Custom logic to match roles starting with "admin".
     * });
     *
     * @param string $name The role for which the matching function is being added.
     * @param Closure $fn A closure that defines the matching function.
     *
     * @return void
     */
    public function addMatchingFunc(string $name, Closure $fn): void;

    /**
     * Adds a custom domain matching function. 
     * This function allows you to perform more customized or domain-specific matches for roles.
     * 
     * Example usage:
     * $roleManager->addDomainMatchingFunc("sales", function($domain) {
     *     return $domain === "sales"; // Custom logic to match the "sales" domain.
     * });
     *
     * @param string $name The domain for which the matching function is being added.
     * @param Closure $fn A closure that defines the domain matching function.
     *
     * @return void
     */
    public function addDomainMatchingFunc(string $name, Closure $fn): void;
}
