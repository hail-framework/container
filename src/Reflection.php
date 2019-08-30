<?php

namespace Hail\Container;


class Reflection
{
    /**
     * @param \ReflectionParameter $param
     *
     * @return string|null
     */
    public static function getParameterType(\ReflectionParameter $param): ?string
    {
        if (!$param->hasType()) {
            return null;
        }

        /** @var \ReflectionType $type */
        $type = $param->getType();

        if ($type === null || $type->isBuiltin()) {
            return null;
        }

        $type = $type->getName();
        $lower = \strtolower($type);

        if ($lower === 'self') {
            return $param->getDeclaringClass()->getName();
        }

        if ($lower === 'parent' && ($parent = $param->getDeclaringClass()->getParentClass())) {
            return $parent->getName();
        }

        return $type;
    }

    /**
     * @param callable $callback
     * @param bool     $toArray
     *
     * @return \ReflectionParameter[]|array[]
     * @throws
     */
    public static function getCallableParameters(callable $callback, bool $toArray = false): array
    {
        if (\is_array($callback)) {
            $reflection = new \ReflectionMethod($callback[0], $callback[1]);
        } elseif (\is_object($callback)) {
            $reflection = new \ReflectionMethod($callback, '__invoke');
        } else {
            $reflection = new \ReflectionFunction($callback);
        }

        $params = $reflection->getParameters();

        if ($params === []) {
            return [];
        }

        if (!$toArray) {
            return $params;
        }

        return static::reflectionParameterToArray($params);
    }

    /**
     * @param string $class
     * @param bool   $toArray
     *
     * @return \ReflectionParameter[]|array[]
     * @throws
     */
    public static function getClassParameters(string $class, bool $toArray = false): array
    {
        if (!\class_exists($class)) {
            throw new \InvalidArgumentException("Class not exists: {$class} (autoloading failed)");
        }

        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new \InvalidArgumentException("Unable to create instance of abstract class: {$class}");
        }

        $constructor = $reflection->getConstructor();

        if (!$constructor || ($params = $constructor->getParameters()) === []) {
            return [];
        }

        if (!$toArray) {
            return $params;
        }

        return static::reflectionParameterToArray($params);
    }

    /**
     * @param \ReflectionParameter[] $params
     *
     * @return array[]
     */
    protected static function reflectionParameterToArray(array $params): array
    {
        $return = [];
        foreach ($params as $param) {
            $array = [
                'name' => $param->name,
                'type' => self::getParameterType($param),
            ];

            if ($param->isOptional()) {
                $array['default'] = $param->getDefaultValue();
            } elseif ($array['type'] && $param->allowsNull()) {
                $array['default'] = null;
            }

            $reflection = $param->getDeclaringFunction();
            $array['file'] = $reflection->getFileName();
            $array['line'] = $reflection->getStartLine();

            $return[] = $array;
        }

        return $return;
    }
}
