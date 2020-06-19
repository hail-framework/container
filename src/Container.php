<?php

namespace Hail\Container;

use Hail\Arrays\ArrayTrait;
use Hail\Singleton\SingletonTrait;
use Psr\Container\ContainerInterface;
use Hail\Container\Exception\{
    InvalidArgumentException,
    NotFoundException
};

class Container implements ContainerInterface, \ArrayAccess
{
    use ArrayTrait;

    /**
     * @var mixed[]
     */
    protected $values = [];

    /**
     * @var callable[]
     */
    protected $factory = [];

    /**
     * @var array
     */
    protected $factoryMap = [];

    /**
     * @var array
     */
    protected $calls = [
        'method' => [],
        'callable' => [],
    ];

    /**
     * @var array
     */
    protected $callsMap = [
        'method' => [],
        'callable' => [],
    ];

    /**
     * @var bool[]
     */
    protected $active = [];

    /**
     * @var string[]
     */
    protected $alias = [];

    public function __construct()
    {
        foreach (
            [
                'di',
                'container',
                __CLASS__,
                static::class,
                ContainerInterface::class,
            ] as $v
        ) {
            $this->values[$v] = $this;
            $this->active[$v] = true;
        }
    }

    /**
     * @param string $name
     *
     * @return mixed
     *
     * @throws NotFoundException
     * @throws InvalidArgumentException
     */
    public function get(string $name)
    {
        $name = $this->name($name);

        switch (true) {
            case isset($this->active[$name]):
                return $this->values[$name];

            case isset($this->values[$name]) || \array_key_exists($name, $this->values):
                break;

            case isset($this->factory[$name]):
                [$factory, $map] = $this->factory[$name];

                if (\is_string($factory) && \class_exists($factory)) {
                    $this->values[$name] = $this->create($factory, $map);
                } else {
                    $this->values[$name] = $this->call($factory, $map);
                }
                break;

            case \class_exists($name):
                $this->values[$name] = $this->create($name);
                break;

            default:
                throw new NotFoundException($name);
        }

        $this->active[$name] = true;

        if (isset($this->calls[$name])) {
            $calls = $this->calls[$name];
            foreach ($calls['method'] as [$method, $map]) {
                $this->call([$this->values[$name], $method], $map);
            }

            foreach ($calls['callable'] as [$call, $map]) {
                $value = $this->call($call, $map);

                if ($value !== null) {
                    $this->values[$name] = $value;
                }
            }
        }

        return $this->values[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->values[$name]) ||
            isset($this->factory[$name]) ||
            isset($this->alias[$name]) ||
            \array_key_exists($name, $this->values);
    }


    /**
     * @param callable                            $callback
     * @param array                               $map
     * @param \ReflectionParameter[]|array[]|null $params
     *
     * @return mixed
     *
     * @throws InvalidArgumentException
     *
     */
    public function call(callable $callback, array $map = [], array $params = null)
    {
        $params = $params ?? Reflection::getCallableParameters($callback);
        if ($params !== []) {
            $params = $this->resolve($params, $map);

            return $callback(...$params);
        }

        return $callback();
    }

    /**
     * @param string                              $class
     * @param mixed[]                             $map
     * @param \ReflectionParameter[]|array[]|null $params
     *
     * @return mixed
     *
     * @throws InvalidArgumentException
     *
     */
    public function create(string $class, array $map = [], array $params = null)
    {
        if (!\class_exists($class)) {
            throw new InvalidArgumentException("Unable to create {$class}");
        }

        if (isset(\class_uses($class)[SingletonTrait::class])) {
            return $class::getInstance();
        }

        if ($params === null) {
            $params = Reflection::getClassParameters($class);
        }

        if ($params !== []) {
            $params = $this->resolve($params, $map, false);
        }

        return new $class(...$params);
    }


    /**
     * @param \ReflectionParameter[]|array[] $params parameter reflections
     * @param array                          $map    parameter values
     * @param bool                           $safe   if TRUE, it's take value from container based on parameter names
     *
     * @return array parameters
     */
    protected function resolve(
        array $params,
        array $map,
        bool $safe = true
    ): array
    {
        $args = [];
        foreach ($params as $index => $param) {
            $value = $this->getParameterValue($param, $index, $map, $safe);

            if ($value instanceof Reference) {
                $value = $value->get($this);
            }

            $args[] = $value;
        }

        return $args;
    }

    protected function getParameterValue(
        $param,
        int $index,
        array $map,
        bool $safe
    )
    {
        if ($isReflection = ($param instanceof \ReflectionParameter)) {
            $name = $param->name;
        } elseif (\is_array($param)) {
            $name = $param['name'];
        } else {
            throw new InvalidArgumentException('Parameter must be the instance of \ReflectionParameter or array');
        }

        if (\array_key_exists($name, $map)) {
            return $map[$name];
        }

        if (\array_key_exists($index, $map)) {
            return $map[$index];
        }

        $type = $isReflection ? Reflection::getParameterType($param) : $param['type'];

        if ($type) {
            if (\array_key_exists($type, $map)) {
                return $map[$type];
            }

            if ($this->has($type)) {
                return $this->get($type);
            }
        }

        if ($safe && $this->has($name)) {
            return $this->get($name);
        }

        if ($isReflection) {
            if ($param->isOptional()) {
                return $param->getDefaultValue();
            }

            if ($type && $param->allowsNull()) {
                return null;
            }

            $reflection = $param->getDeclaringFunction();
            $file = $reflection->getFileName();
            $line = $reflection->getStartLine();
        } elseif (\array_key_exists('default', $param)) {
            return $param['default'];
        } else {
            ['file' => $file, 'line' => $line] = $param;
        }

        // unresolved - throw a container exception:
        throw new InvalidArgumentException(
            "Unable to resolve parameter: \${$name} " . ($type ? "({$type}) " : '') .
            'in file: ' . $file . ', line ' . $line
        );
    }

    public function insert(string $name, $value): void
    {
        if ($this->has($name)) {
            throw new InvalidArgumentException("Try to override the existing '{$name}'");
        }

        $this->values[$name] = $value;
        $this->active[$name] = true;

        unset($this->alias[$name]);
    }

    public function replace(string $name, $value)
    {
        if (!isset($this->active[$name])) {
            throw new InvalidArgumentException("'{$name}' not initialized");
        }

        $this->values[$name] = $value;
        unset($this->alias[$name]);
    }

    public function register(string $name, $define = null, array $map = []): void
    {
        if (isset($this->active[$name])) {
            throw new InvalidArgumentException("Try to override the existing '{$name}'");
        }

        switch (true) {
            case $define instanceof \Closure:
            case \is_string($define) && \class_exists($define):
                $func = $define;
                break;

            case \is_callable($define):
                $func = \Closure::fromCallable($define);
                break;

            case \is_array($define):
                $func = $name;
                $map = $define;
                break;

            case null === $define:
                $func = $name;
                $map = [];
                break;

            default:
                throw new InvalidArgumentException('Unexpected argument type for $define: ' . \gettype($define));
        }

        $this->factory[$name] = [$func, $map];

        unset($this->values[$name], $this->alias[$name]);
    }

    public function set(string $name, $value): void
    {
        if (isset($this->active[$name])) {
            throw new InvalidArgumentException("Try to override the existing '{$name}'");
        }

        $this->values[$name] = $value;

        unset(
            $this->factory[$name],
            $this->alias[$name]
        );
    }

    public function alias(string $alias, string $abstract): void
    {
        if ($alias === $abstract) {
            throw new InvalidArgumentException('Alias cannot be the same as the existing name');
        }

        if (\array_key_exists($alias, $this->values) || isset($this->factory[$alias])) {
            throw new InvalidArgumentException("'$alias' already defined in container");
        }

        if (!$this->has($abstract)) {
            throw new InvalidArgumentException("'$abstract' not defined in container");
        }

        $this->alias[$alias] = $abstract;
    }

    protected function name(string $name): string
    {
        while (isset($this->alias[$name])) {
            $name = $this->alias[$name];
        }

        return $name;
    }

    public function calls(string $name, string $method, array $map = []): void
    {
        $name = $this->name($name);
        if (isset($this->active[$name])) {
            throw new InvalidArgumentException("'$name' already initialized");
        }

        $this->calls['method'][$name][] = [$method, $map];
    }

    public function configure(string $name, callable $fun, array $map = []): void
    {
        $name = $this->name($name);
        if (isset($this->active[$name])) {
            throw new InvalidArgumentException("'$name' already initialized");
        }

        if ($map === [] || !\array_key_exists(0, $map)) {
            $map[0] = $this->ref($name);
        }

        $this->calls['callable'][$name][] = [$fun, $map];
    }

    public function ref(string $name): Reference
    {
        if (isset($this->active[$name])) {
            return $this->values[$name];
        }

        return Reference::create($name);
    }

    public function delete(string $name): void
    {
        unset(
            $this->active[$name],
            $this->values[$name],
            $this->factory[$name],
            $this->alias[$name],
            $this->calls[$name]
        );
    }

    public function __call(string $name, array $arguments)
    {
        return $this->get($name);
    }
}
