<?php

declare(strict_types=1);

namespace CodeVault;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Minimal dependency-injection container: bindings, singletons, and
 * constructor autowiring via reflection for anything not explicitly bound.
 */
class Container
{
    /** @var array<string, Closure> */
    private array $bindings = [];

    /** @var array<string, bool> */
    private array $singletons = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function bind(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->register($abstract, $concrete, false);
    }

    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->register($abstract, $concrete, true);
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]) || class_exists($abstract);
    }

    /**
     * @template T
     * @param class-string<T>|string $abstract
     * @return T|mixed
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            $object = ($this->bindings[$abstract])($this, $parameters);

            if ($this->singletons[$abstract] ?? false) {
                $this->instances[$abstract] = $object;
            }

            return $object;
        }

        return $this->build($abstract, $parameters);
    }

    private function register(string $abstract, Closure|string|null $concrete, bool $shared): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        if (is_string($concrete)) {
            $className = $concrete;
            $concrete = fn (Container $c, array $params) => $c->build($className, $params);
        }

        $this->bindings[$abstract] = $concrete;
        $this->singletons[$abstract] = $shared;
        unset($this->instances[$abstract]);
    }

    private function build(string $className, array $parameters = []): object
    {
        if (!class_exists($className)) {
            throw new RuntimeException("Cannot resolve unknown class [{$className}].");
        }

        $reflector = new ReflectionClass($className);

        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Class [{$className}] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $className();
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $parameters)) {
                $dependencies[] = $parameters[$name];
                continue;
            }

            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $this->make($type->getName());
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                "Cannot resolve parameter [{$name}] for class [{$className}]."
            );
        }

        return $reflector->newInstanceArgs($dependencies);
    }
}
