<?php

declare(strict_types=1);

namespace Plugins\Authorization\Engine\RBAC;

use Closure;
use Plugins\Authorization\Engine\Log\Logger\DefaultLogger;
use Plugins\Authorization\Engine\Interfaces\RoleManager as RoleManagerInterface;
use Plugins\Authorization\Engine\RBAC\Supports\RoleManager as SupportRoleManager;

/**
 * Class RoleManager.
 * Provides a default implementation for the RoleManager interface.
 *
 * @author techlee@qq.com
 * @author 1692898084@qq.com
 */
class RoleManager implements RoleManagerInterface
{
    use SupportRoleManager;
    
    /**
     * RoleManager constructor.
     *
     * @param int $maxHierarchyLevel
     * @param Closure|null $matchingFunc
     */
    public function __construct(int $maxHierarchyLevel, ?Closure $matchingFunc = null)
    {
        $this->clear();
        $this->maxHierarchyLevel = $maxHierarchyLevel;
        $this->matchingFunc = $matchingFunc;
        $this->setLogger(new DefaultLogger());
    }

    /**
     * @param RoleManager $roleManager
     */
    public function copyFrom(RoleManager &$roleManager): void
    {
        $this->rangeLinks($roleManager->allRoles, function ($name1, $name2, $domain) {
            $this->addLink($name1, $name2, $domain);
        });
    }
}
