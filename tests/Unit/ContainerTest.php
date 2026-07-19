<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Container;
use CodeVault\Tests\Fixtures\Car;
use CodeVault\Tests\Fixtures\EngineInterface;
use CodeVault\Tests\Fixtures\NoDependencies;
use CodeVault\Tests\Fixtures\V8Engine;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContainerTest extends TestCase
{
    public function test_bind_resolves_via_closure(): void
    {
        $container = new Container();
        $container->bind('answer', fn () => 42);

        $this->assertSame(42, $container->make('answer'));
    }

    public function test_singleton_returns_the_same_instance_every_time(): void
    {
        $container = new Container();
        $container->singleton(NoDependencies::class);

        $first = $container->make(NoDependencies::class);
        $second = $container->make(NoDependencies::class);

        $this->assertSame($first, $second);
    }

    public function test_bind_without_singleton_returns_fresh_instances(): void
    {
        $container = new Container();
        $container->bind(NoDependencies::class);

        $first = $container->make(NoDependencies::class);
        $second = $container->make(NoDependencies::class);

        $this->assertNotSame($first, $second);
    }

    public function test_autowires_class_with_no_constructor(): void
    {
        $container = new Container();

        $instance = $container->make(NoDependencies::class);

        $this->assertTrue($instance->built);
    }

    public function test_autowires_constructor_dependencies_bound_to_an_interface(): void
    {
        $container = new Container();
        $container->bind(EngineInterface::class, V8Engine::class);

        /** @var Car $car */
        $car = $container->make(Car::class);

        $this->assertInstanceOf(V8Engine::class, $car->engine);
        $this->assertSame(400, $car->engine->horsepower());
        $this->assertSame('unnamed', $car->model);
    }

    public function test_explicit_parameters_override_autowiring(): void
    {
        $container = new Container();
        $container->bind(EngineInterface::class, V8Engine::class);

        /** @var Car $car */
        $car = $container->make(Car::class, ['model' => 'Roadster']);

        $this->assertSame('Roadster', $car->model);
    }

    public function test_make_throws_for_an_unresolvable_interface(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);
        $container->make(EngineInterface::class);
    }

    public function test_instance_registers_a_pre_built_object(): void
    {
        $container = new Container();
        $engine = new V8Engine();

        $container->instance(EngineInterface::class, $engine);

        $this->assertSame($engine, $container->make(EngineInterface::class));
    }
}
