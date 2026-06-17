<?php

declare(strict_types=1);

namespace Plugins\Authorization\Engine\RBAC;

use Plugins\Authorization\Engine\RBAC\Supports\DomainManager as SupportDomainManager;

/**
 * Class DomainManager.
 * Provides a default implementation for the RoleManager interface with domain support.
 *
 * @author 1692898084@qq.com
 */
class DomainManager extends RoleManager
{
    use SupportDomainManager;
    
    /**
     * @var array<string, RoleManager>
     */
    protected array $rmMap = [];

    /**
     * DomainManager constructor.
     *
     * @param int $maxHierarchyLevel
     */
    public function __construct(int $maxHierarchyLevel)
    {
        parent::__construct($maxHierarchyLevel);
    }
}
