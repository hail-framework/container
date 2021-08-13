<?php

namespace Hail\Container;


class Reflection
{
    public static function getParameterType(\ReflectionParameter $param): ?string
    {
        if (!$param->hasType()) {
            return null;
        }

        $type = $param->getType();

        if ($type === null || $type->isBuiltin()) {
            return null;
        }

        $type = $type->getName();
        $lower = \strtolower($type);

        if ($lower === 'self' || $lower === 'static') {
            return $param->getDeclaringClass()?->getName();
        }

        if ($lower === 'parent' && ($parent = $param->getDeclaringClass()?->getParentClass())) {
            return $parent->getName();
        }

        return $type;
    }

    public static function getCallableParameters(callable $callback, bool $toArray = false): array
    {
        $reflection = new \ReflectionFunction(
            \Closure::fromCallable($callback)
        );
        $params = $reflection->getParameters();

        if ($params === []) {
            return [];
        }

        if (!$toArray) {
            return $params;
        }

        return static::reflectionParameterToArray($params);
    }

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

    protected static function reflectionParameterToArray(array $params): array
    {
        $return = [];
        foreach ($params as $param) {
            if (!$param instanceof \ReflectionParameter) {
                throw new \RuntimeException("Invalid parameter type");
            }

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
