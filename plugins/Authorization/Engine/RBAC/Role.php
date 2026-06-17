<?php

declare(strict_types=1);

namespace Plugins\Authorization\Engine\RBAC;

use Closure;

/**
 * Role.
 * Represents the data structure for a role in Role-Based Access Control (RBAC).
 * This class allows managing users, roles, and relationships between them.
 *
 * It provides functionality to:
 * - Add and remove roles.
 * - Add and remove users.
 * - Manage role-to-role matching.
 * - Apply link conditions to roles.
 * - Convert role data into a string representation.
 *
 * @package Plugins\Authorization\Engine
 */
class Role
{
    /**
     * @var string
     */
    public string $name = '';

    /**
     * @var array<string, Role>
     */
    public array $roles = [];

    /**
     * @var array<string, Role>
     */
    private array $users = [];

    /**
     * @var array<string, Role>
     */
    private array $matched = [];

    /**
     * @var array<string, Role>
     */
    private array $matchedBy = [];

    /**
     * @var array<string, Closure>
     */
    private array $linkConditionFuncMap = [];

    /**
     * @var array<string, array>
     */
    private array $linkConditionFuncParamsMap = [];

    /**
     * Role constructor.
     *
     * @param string $name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Add a role to this role's list of roles.
     *
     * @param self $role
     */
    public function addRole(self $role): void
    {
        $this->roles[$role->name] = $role;
        $role->addUser($this);
    }

    /**
     * Remove a role from this role's list of roles.
     *
     * @param self $role
     */
    public function removeRole(self $role): void
    {
        unset($this->roles[$role->name]);
        $role->removeUser($this);
    }

    /**
     * Add a user to this role's list of users.
     *
     * @param self $user
     */
    public function addUser(self $user): void
    {
        $this->users[$user->name] = $user;
    }

    /**
     * Remove a user from this role's list of users.
     *
     * @param self $user
     */
    public function removeUser(self $user): void
    {
        unset($this->users[$user->name]);
    }

    /**
     * Add a matching role to this role.
     *
     * @param self $role
     */
    public function addMatch(self $role): void
    {
        $this->matched[$role->name] = $role;
        $role->matchedBy[$this->name] = $this;
    }

    /**
     * Remove a matching role from this role.
     *
     * @param self $role
     */
    public function removeMatch(self $role): void
    {
        unset($this->matched[$role->name]);
        unset($role->matchedBy[$this->name]);
    }

    /**
     * Remove all matches for this role.
     */
    public function removeMatches(): void
    {
        foreach ($this->matched as &$role) {
            $this->removeMatch($role);
        }
        foreach ($this->matchedBy as &$role) {
            $role->removeMatch($this);
        }
    }

    /**
     * Applies a callback to all roles that this role matches.
     *
     * @param Closure $fn
     */
    public function rangeRoles(Closure $fn): void
    {
        array_walk($this->roles, function (&$role, $name) use ($fn) {
            $fn($name, $role);
        });

        array_walk($this->roles, function ($role) use ($fn) {
            array_walk($role->matched, function (&$value, $key) use ($fn) {
                $fn($key, $value);
            });
        });

        array_walk($this->matchedBy, function ($role) use ($fn) {
            array_walk($role->roles, function (&$value, $key) use ($fn) {
                $fn($key, $value);
            });
        });
    }

    /**
     * Applies a callback to all users that this role matches.
     *
     * @param Closure $fn
     */
    public function rangeUsers(Closure $fn): void
    {
        array_walk($this->users, function (&$user, $name) use ($fn) {
            $fn($name, $user);
        });

        array_walk($this->users, function ($user) use ($fn) {
            array_walk($user->matched, function (&$value, $key) use ($fn) {
                $fn($key, $value);
            });
        });

        array_walk($this->matchedBy, function ($user) use ($fn) {
            array_walk($user->users, function (&$value, $key) use ($fn) {
                $fn($key, $value);
            });
        });
    }

     /**
     * Converts the role to a string representation.
     * The string contains the role's name and the names of roles it contains or matches.
     *
     * @return string The string representation of the role.
     */
    public function toString(): string
    {
        $len = count($this->roles);

        if (0 == $len) {
            return '';
        }

        $names = implode(', ', $this->getRoles());

        if (1 == $len) {
            return $this->name . ' < ' . $names;
        } else {
            return $this->name . ' < (' . $names . ')';
        }
    }

    /**
     * Returns a list of all roles that this role matches.
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        $names = [];
        $this->rangeRoles(function ($name, $role) use (&$names) {
            $names[] = $name;
        });
        return array_uniqueness($names);
    }

    /**
     * Returns a list of all users that this role matches.
     *
     * @return string[]
     */
    public function getUsers(): array
    {
        $names = [];
        $this->rangeUsers(function ($name, $user) use (&$names) {
            $names[] = $name;
        });
        return $names;
    }

     /**
     * Adds a link condition function to this role.
     * A link condition function is used to define specific conditions for linking roles.
     *
     * @param Role $role The role to which the link condition applies.
     * @param string $domain The domain for the link condition.
     * @param Closure $fn The link condition function.
     */
    public function addLinkConditionFunc(Role $role, string $domain, Closure $fn): void
    {
        $this->linkConditionFuncMap[$this->getLinkConditionFuncKey($role, $domain)] = $fn;
    }

    /**
     * Gets the link condition function for a role, if it exists.
     *
     * @param Role $role The role to which the link condition applies.
     * @param string $domain The domain for the link condition.
     * 
     * @return Closure|null The link condition function or null if none exists.
     */
    public function getLinkConditionFunc(Role $role, string $domain): ?Closure
    {
        $key = $this->getLinkConditionFuncKey($role, $domain);
        return $this->linkConditionFuncMap[$key] ?? null;
    }

    /**
     * Sets parameters for a link condition function.
     *
     * @param Role $role The role to which the link condition applies.
     * @param string $domain The domain for the link condition.
     * @param array $params The parameters to set for the link condition.
     */
    public function setLinkConditionFuncParams(Role $role, string $domain, ...$params): void
    {
        $this->linkConditionFuncParamsMap[$this->getLinkConditionFuncKey($role, $domain)] = $params;
    }

     /**
     * Gets the parameters for a link condition function.
     *
     * @param Role $role The role to which the link condition applies.
     * @param string $domain The domain for the link condition.
     * 
     * @return array|null The parameters for the link condition or null if none exist.
     */
    public function getLinkConditionFuncParams(Role $role, string $domain): ?array
    {
        $key = $this->getLinkConditionFuncKey($role, $domain);
        return $this->linkConditionFuncParamsMap[$key] ?? null;
    }

    /**
     * Generates a key for the link condition function map.
     * The key is a combination of the role name and the domain.
     *
     * @param Role $role The role to which the link condition applies.
     * @param string $domain The domain for the link condition.
     * 
     * @return string The generated key.
     */
    private function getLinkConditionFuncKey(Role $role, string $domain): string
    {
        return $role->name . '_' . $domain;
    }
}
