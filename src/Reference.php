<?php

namespace Hail\Container;

use Psr\Container\ContainerInterface;

class Reference
{
    /**
     * @var self[]
     */
    private static $instances;

    /**
     * @var string
     */
    private $name;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function create(string $name): self
    {
        if (!isset(self::$instances[$name])) {
            self::$instances[$name] = new self($name);
        }

        return self::$instances[$name];
    }

    public function get(ContainerInterface $container)
    {
        return $container->get($this->name);
    }
}
