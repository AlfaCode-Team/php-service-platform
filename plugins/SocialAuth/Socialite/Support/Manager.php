<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Socialite\Support;

use InvalidArgumentException;
use PHPShots\Common\Interfaces\ContainerInterface;

/**
 * Minimal driver manager.
 *
 * Resolves a named driver to a create{Name}Driver() factory method on the
 * concrete manager and caches the built instance. Fits the GDA container
 * (bind-it) without dragging in a full framework manager.
 */
abstract class Manager
{
    protected ?ContainerInterface $container = null;

    /** @var array<string,object> */
    protected array $drivers = [];

    abstract public function getDefaultDriver();

    public function driver($driver = null)
    {
        $driver ??= $this->getDefaultDriver();

        if ($driver === null) {
            throw new InvalidArgumentException(
                sprintf('Unable to resolve NULL driver for [%s].', static::class)
            );
        }

        return $this->drivers[$driver] ??= $this->createDriver($driver);
    }

    protected function createDriver(string $driver): mixed
    {
        $method = 'create' . str_replace(['-', '_'], '', ucwords($driver, '-_')) . 'Driver';

        if (method_exists($this, $method)) {
            return $this->{$method}();
        }

        throw new InvalidArgumentException("Driver [{$driver}] not supported.");
    }

    public function setContainer(ContainerInterface $container): static
    {
        $this->container = $container;
        return $this;
    }
}
