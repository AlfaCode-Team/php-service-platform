<?php

declare(strict_types=1);

namespace Plugins\Authorization\Engine\Interfaces;

/**
 * Interface CacheableParam.
 * 
 * @author 1692898084@qq.com
 */
interface CacheableParam
{
    /**
     * Returns a cache key.
     *
     * @return string
     */
    public function getCacheKey(): string;
}