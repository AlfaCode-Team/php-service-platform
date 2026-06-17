<?php

declare(strict_types=1);

namespace Plugins\Authorization\Engine\Interfaces;

use Closure;

/**
 * Interface ConditionalRoleManager.
 * Extends the RoleManager interface to include support for conditional role-linking.
 * This interface provides methods to add conditions for linking users to roles and roles to domains, 
 * ensuring that role assignments only occur when specified conditions are met.
 * 
 * @package Plugins\Authorization\Engine
 * @author Hakeem Rasheed <hakimushamavu@gmail.com>
 */
interface ConditionalRoleManager extends RoleManager
{
    /**
     * Adds a conditional function for linking a user to a role.
     * The link will only be valid if the condition function `fn` returns true.
     * This allows for conditional role assignments based on specific logic (e.g., user age, group, etc.).
     * 
     * Example usage:
     * $roleManager->addLinkConditionFunc("john", "admin", function($userName, $roleName) {
     *     return $userName === "john" && $roleName === "admin"; // Only link "john" to "admin".
     * });
     *
     * @param string $userName The user to be linked to a role (e.g., "john").
     * @param string $roleName The role to be assigned to the user (e.g., "admin").
     * @param Closure $linkConditionFunc The condition function that must return true for the link to be valid.
     *
     * @return void
     */
    public function addLinkConditionFunc(string $userName, string $roleName, Closure $linkConditionFunc): void;

    /**
     * Sets the parameters for the condition function used in the link between user and role.
     * This is used to provide additional context (parameters) to the condition function.
     * 
     * Example usage:
     * $roleManager->setLinkConditionFuncParams("john", "admin", "extra_param");
     * // Sets "extra_param" as an additional parameter for the link condition function.
     *
     * @param string $userName The user to whom the role is being linked (e.g., "john").
     * @param string $roleName The role being linked to the user (e.g., "admin").
     * @param string ...$params Additional parameters for the condition function.
     *
     * @return void
     */
    public function setLinkConditionFuncParams(string $userName, string $roleName, string ...$params): void;

    /**
     * Adds a conditional function for linking a user to a role within a specific domain.
     * The link will only be valid if the condition function `fn` returns true.
     * This supports more granular role assignments by domain, ensuring roles are applied conditionally in different contexts.
     * 
     * Example usage:
     * $roleManager->addDomainLinkConditionFunc("john", "admin", "sales", function($userName, $roleName, $domain) {
     *     return $userName === "john" && $roleName === "admin" && $domain === "sales"; // Only link in the "sales" domain.
     * });
     *
     * @param string $userName The user to be linked to a role (e.g., "john").
     * @param string $roleName The role to be assigned to the user (e.g., "admin").
     * @param string $domain The domain within which the role assignment will occur (e.g., "sales").
     * @param Closure $linkConditionFunc The condition function that must return true for the link to be valid.
     *
     * @return void
     */
    public function addDomainLinkConditionFunc(string $userName, string $roleName, string $domain, Closure $linkConditionFunc): void;

    /**
     * Sets the parameters for the condition function used in the link between user and role within a specific domain.
     * This is useful for passing additional context that may affect the role assignment logic within the domain.
     * 
     * Example usage:
     * $roleManager->setDomainLinkConditionFuncParams("john", "admin", "sales", "extra_param");
     * // Sets "extra_param" as an additional parameter for the domain-specific link condition function.
     *
     * @param string $userName The user to whom the role is being linked (e.g., "john").
     * @param string $roleName The role being linked to the user (e.g., "admin").
     * @param string $domain The domain in which the role assignment is valid (e.g., "sales").
     * @param string ...$params Additional parameters for the condition function.
     *
     * @return void
     */
    public function setDomainLinkConditionFuncParams(string $userName, string $roleName, string $domain, string ...$params): void;
}
