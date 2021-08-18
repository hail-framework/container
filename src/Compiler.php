<?php

namespace Hail\Container;

use Hail\Arrays\Arrays;

class Compiler
{
    protected array $points = [];
    protected array $methods = [];

    protected array $alias = [];
    protected array $abstractAlias = [];

    public function __construct(
        protected array $config
    ) {
    }

    public function compile(): string
    {
        $this->parseServices();

        $entryPoints = [];
        foreach ($this->points as $k => $v) {
            $k = $this->classname($k);
            $entryPoints[] = "$k => $v,";
        }

        $alias = [];
        foreach ($this->alias as $k => $v) {
            $k = $this->classname($k);
            $v = $this->classname($v);
            $alias[] = "$k => $v,";
        }

        $abstractAlias = [];
        foreach ($this->abstractAlias as $k => $v) {
            $sub = [];
            foreach ($v as $n) {
                $sub[] = $this->classname($n) . ',';
            }

            $k = $this->classname($k);
            $abstractAlias[] = "$k => [\n" . \implode("\n\t\t\t", $sub);
            $abstractAlias[] = '],';
        }

        $methods = $this->methods;

        \ob_start();
        include __DIR__ . '/template/Container.phtml';

        return "<?php\n" . \ob_get_contents();
    }

    protected function parseServices(): void
    {
        $services = $this->config ?? [];
        $alias = [];

        foreach ($services as $k => $v) {
            if (\is_string($v)) {
                if ($v[0] === '@') {
                    if (\strpos($v, '->') > 0) {
                        $this->toMethod($k, $this->parseStr($v));
                    } else {
                        $alias[$k] = \substr($v, 1);
                    }
                } else {
                    $factory = $this->parseStrToClass($v);
                    if ($this->isClassname($v)) {
                        $alias[$v] = $k;
                    }
                    $this->toMethod($k, "{$factory}()");
                }

                continue;
            }

            if (!\is_array($v)) {
                continue;
            }

            if (!Arrays::isAssoc($v)) {
                if ($this->isClassname($k)) {
                    $this->toMethod($k, $this->classInstance($k . ':' . $this->parseArguments($v)));
                }
                continue;
            }

            if (isset($v['alias'])) {
                if (\strpos($v['alias'], '->') > 0) {
                    $this->toMethod($k, $this->parseStr('@' . $v['alias']));
                } else {
                    $alias[$k] = $v['alias'];
                }
                continue;
            }

            $to = $v['to'] ?? [];
            if ($to === true) {
                if (isset($v['class'])) {
                    $alias[$v['class']] = $k;
                }
            } else {
                $to = (array) $to;
                foreach ($to as $ref) {
                    $alias[$ref] = $k;
                }
            }

            $arguments = '';
            if (isset($v['arguments'])) {
                $arguments = $this->parseArguments($v['arguments']);
            }

            $suffix = \array_merge(
                $this->parseProperty($v['property'] ?? []),
                $this->parseCalls($v['calls'] ?? [])
            );

            if (isset($v['factory'])) {
                $factory = $v['factory'];
                if (\is_array($v['factory'])) {
                    [$c, $m] = $v['factory'];
                    $factory = "{$c}::{$m}";
                }

                if (!\is_string($factory)) {
                    continue;
                }
            } elseif (isset($v['class'])) {
                $factory = $v['class'];
            } elseif ($this->isClassname($k)) {
                $factory = $k;
            } else {
                throw new \RuntimeException('Component not defined any build arguments: ' . $k);
            }

            $factory = $this->parseStrToClass($factory);
            $this->toMethod($k, "{$factory}($arguments)", $suffix);
        }

        $this->alias = $alias;
        foreach ($alias as $k => $v) {
            if (!isset($this->abstractAlias[$v])) {
                $this->abstractAlias[$v] = [];
            }

            $this->abstractAlias[$v][] = $k;
        }
    }

    protected function parseArguments(array $args): string
    {
        $ret = '';
        foreach ($args as $arg) {
            $ret .= $this->parseStr($arg) . ',';
        }

        return $ret;
    }

    protected function parseProperty(array $props): array
    {
        if ($props === []) {
            return [];
        }

        $return = [];
        foreach ($props as $k => $v) {
            $return[] = $k . ' = ' . $this->parseStr($v);
        }

        return $return;
    }

    protected function parseCalls(array $calls): array
    {
        if ($calls === []) {
            return [];
        }

        $return = [];
        foreach ($calls as $method => $v) {
            $args = '';
            if (\is_array($v)) {
                $args = $this->parseArguments($v);
            }

            $return[] = $method . '(' . $args . ')';
        }

        return $return;
    }

    protected function parseStrToClass(string $str): string
    {
        if ($str[0] === '@') {
            return $this->parseRef(
                \substr($str, 1)
            );
        }

        if (\str_contains($str, '::')) {
            [$class, $method] = \explode('::', $str, 2);
            $parts = \explode($method, ':', 2);
            $method = $parts[0];

            $method .= $this->parseArgs($parts[1] ?? '');

            return "{$class}::{$method}";
        }

        return $this->classInstance($str);
    }

    protected function classInstance(string $str): string
    {
        $parts = \explode($str, ':', 2);
        $class = $parts[0];

        if (!$this->isClassname($class)) {
            throw new \RuntimeException("Given value is not a class name: $str");
        }

        if (\method_exists($class, 'getInstance')) {
            return "{$class}::getInstance()";
        }

        return "new $class" . $this->parseArgs($parts[1] ?? '');
    }

    protected function parseStr(string $str): string
    {
        if (isset($str[0], $str[1]) &&
            $str[0] === '@' &&
            $str[1] !== '@'
        ) {
            return $this->parseRef(
                \substr($str, 1)
            );
        }

        return \var_export($str, true);
    }

    protected function parseRef(string $name): string
    {
        $parts = \explode('->', $name);
        $name = \array_shift($parts);

        $return = '$this->get(' . $this->classname($name) . ')';
        foreach ($parts as $v) {
            $v = \explode(':', $v, 2);
            $return .= '->' . $v[0];
            if (isset($v[1])) {
                $return .= $this->parseArgs($v[1]);
            }
        }

        return $return;
    }

    protected function parseArgs(string $str): string
    {
        if ($str === '') {
            return '()';
        }

        $args = $this->parseArguments(\explode(',', $str));
        return '(' . $args . ')';
    }

    protected function isClassname(string $name): bool
    {
        return (\class_exists($name) || \interface_exists($name)) && \strtoupper($name[0]) === $name[0];
    }

    protected function classname(string $name): string
    {
        if ($name[0] === '\\') {
            $name = \ltrim($name, '\\');
        }

        if ($this->isClassname($name)) {
            return "$name::class";
        }

        return \var_export($name, true);
    }

    protected function methodName(string $string): string
    {
        if ($string[0] === '\\') {
            $string = \ltrim($string, '\\');
        }

        $name = 'HAIL_';
        if ($this->isClassname($string)) {
            $name .= 'CLASS__';
        } else {
            $name .= 'PARAM__';
        }

        $name .= \str_replace(['\\', '.'], ['__', '_'], $string);

        return $name;
    }

    protected function toPoint(string $name, string $point = null): string
    {
        $method = $this->methodName($point ?? $name);
        $this->points[$name] = "'$method'";

        return $method;
    }

    protected function toMethod(string $name, string $return, array $suffix = []): void
    {
        $method = $this->toPoint($name);

        $code = "\tprotected function {$method}() {\n";
        if ($suffix !== []) {
            $code .= "\t\t\$object = $return;\n";
            $code .= "\t\t\$object->" . \implode(";\n\t\t\$object->", $suffix) . ";\n";
            $return = '$object';
        }

        $code .= "\t\treturn $return;\n";
        $code .= "\t}";

        $this->methods[] = $code;
    }
}
