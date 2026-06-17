<?php

declare(strict_types=1);

namespace Plugins\Authorization\Engine\RBAC\Supports;

use Closure;
use Plugins\Authorization\Engine\Interfaces\Logger;

/**
 * Trait BaseManager.
 * Provides base functionality for role and permission management.
 * Includes properties and methods for managing hierarchy levels, condition matching functions, and logging.
 * 
 * @package Plugins\Authorization\Engine
 */
trait BaseManager
{
    /**
     * @var int The maximum allowed hierarchy level for role assignments.
     * Used to limit the depth of nested role hierarchies.
     */
    protected int $maxHierarchyLevel = 10;

    /**
     * @var Closure|null A callback function used to match roles or conditions.
     * The function is invoked to evaluate specific matching logic (e.g., checking role properties).
     */
    protected ?Closure $matchingFunc = null;

    /**
     * @var Closure|null A callback function used to match roles based on domain-specific conditions.
     * The function is invoked to evaluate conditions within specific domains, adding granularity to the role checks.
     */
    protected ?Closure $domainMatchingFunc = null;

    /**
     * @var Logger
     */
    protected Logger $logger;

    /**
     * Sets the current logger.
     *
     * @param Logger $logger
     *
     * @return void
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }



    /**
     * Sets the matching function for roles or conditions.
     * 
     * The matching function is invoked to evaluate whether certain conditions (such as role properties) are met.
     * For example, you can define custom matching logic for assigning roles or permissions.
     * 
     * Example usage:
     * $roleManager->setMatchingFunc(function($role) {
     *     return $role->hasPermission('view_dashboard');
     * });
     *
     * @param Closure $matchingFunc The function to match roles or conditions.
     *
     * @return void
     */
    public function setMatchingFunc(Closure $matchingFunc): void
    {
        $this->matchingFunc = $matchingFunc;
    }

    /**
     * Sets the domain-specific matching function.
     * 
     * This function adds granularity to role or permission assignments by matching conditions based on the domain.
     * For example, a user might have a role in one domain but not another, and this method helps evaluate that.
     * 
     * Example usage:
     * $roleManager->setDomainMatchingFunc(function($role, $domain) {
     *     return $domain === 'admin' && $role->hasPermission('view_dashboard');
     * });
     *
     * @param Closure $domainMatchingFunc The function to match roles based on domain-specific conditions.
     *
     * @return void
     */
    public function setDomainMatchingFunc(Closure $domainMatchingFunc): void
    {
        $this->domainMatchingFunc = $domainMatchingFunc;
    }

    /**
     * Gets the current matching function.
     * 
     * This method returns the matching function, which can be used to evaluate roles or conditions.
     * 
     * Example usage:
     * $matchingFunc = $roleManager->getMatchingFunc();
     * 
     * @return Closure|null The current matching function, or null if not set.
     */
    public function getMatchingFunc(): ?Closure
    {
        return $this->matchingFunc;
    }

    /**
     * Gets the current domain-specific matching function.
     * 
     * This method returns the domain-specific matching function, used for evaluating conditions within a domain.
     * 
     * Example usage:
     * $domainMatchingFunc = $roleManager->getDomainMatchingFunc();
     * 
     * @return Closure|null The current domain-specific matching function, or null if not set.
     */
    public function getDomainMatchingFunc(): ?Closure
    {
        return $this->domainMatchingFunc;
    }

    /**
     * Gets the maximum hierarchy level.
     * 
     * This method returns the maximum allowed level of nested roles, ensuring role assignments do not exceed 
     * the defined maximum hierarchy level.
     * 
     * Example usage:
     * $maxLevel = $roleManager->getMaxHierarchyLevel();
     * 
     * @return int The maximum hierarchy level for role assignments.
     */
    public function getMaxHierarchyLevel(): int
    {
        return $this->maxHierarchyLevel;
    }
}
