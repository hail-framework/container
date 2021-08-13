<?php

namespace Hail\Container;

use Psr\Container\ContainerInterface;

class Reference
{
    /**
     * @var self[]
     */
    private static array $instances;

    private function __construct(
        private string $name
    ) {
    }

    public static function create(string $name): self
    {
        return self::$instances[$name] ??= new self($name);
    }

    public function get(ContainerInterface $container)
    {
        return $container->get($this->name);
    }
}
