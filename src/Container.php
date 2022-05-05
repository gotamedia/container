<?php

declare(strict_types=1);

namespace Atoms\Container;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

class Container implements ContainerInterface
{
    public const BIND_SHARED = 0;
    public const BIND_NONSHARED = 1;
    public array $instances = [];
    public array $bindings = [];

    /**
     * {@inheritDoc}
     *
     * @param array $parameters
     */
    public function get($id, array $parameters = [])
    {
        return $this->make($id, $parameters);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException
     */
    public function has($id)
    {
        if (!is_string($id)) {
            throw new InvalidArgumentException('Invalid ID; must be string');
        }

        if (isset($this->bindings[$id])) {
            return true;
        }

        if (isset($this->instances[$id])) {
            return true;
        }

        try {
            $reflector = new ReflectionClass($id);
        } catch (ReflectionException) {
            return false;
        }

        if (!$reflector->isInstantiable()) {
            return false;
        }

        return true;
    }

    /**
     * Register a binding.
     *
     * @param string $abstract
     * @param string|\Closure|null $concrete
     * @param int $shared
     */
    public function bind(
        string $abstract,
        $concrete = null,
        int $shared = self::BIND_NONSHARED
    ): void {
        /**
         * If no concrete type was given, set the concrete type to the abstract
         * type. This allows the concrete type to be registered as shared
         * without being forced to state their classes in both of the parameter.
         */
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        /**
         * If the concrete type is not a Closure, assume it contains a class name
         * which is bound to the abstract type and wrap it inside of a Closure.
         */
        if (!$concrete instanceof Closure) {
            $concrete = $this->makeClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');
    }

    /**
     * Register a shared binding in the container. Only the abstract name is needed
     * although a shorter or different name can be given if wanted.
     *
     * @param string $abstract
     * @param string|null|\Closure $concrete
     */
    public function share(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, self::BIND_SHARED);
    }

    /**
     * Register an existing instance in the container as shared.
     *
     * @param string $abstract
     * @param mixed $instance
     */
    public function instance(string $abstract, $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * Resolve the given type from the container.
     *
     * @param string $abstract
     * @param array $parameters
     * @return mixed
     */
    public function make(string $abstract, array $parameters = [])
    {
        /**
         * If a shared instance of the requested type has already been created,
         * return the existing instance instead of instantiating a new one.
         */
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->getConcrete($abstract);

        /**
         * Instantiate an instance of the concrete type registered for the binding.
         * This will instantiate the types and resolve any of the nested dependencies
         * recursively until all have gotten resolved.
         */
        $object = $this->isBuildable($concrete, $abstract)
            ? $this->build($concrete, $parameters)
            : $this->make($concrete, $parameters);

        /**
         * If the requested type is registered as shared (singleton), make sure
         * to cache the instance.
         */
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Determine if a given type is bound.
     *
     * @param string $abstract
     * @return bool
     */
    public function isBound(string $abstract): bool
    {
        return array_key_exists($abstract, $this->bindings) || array_key_exists($abstract, $this->instances);
    }

    /**
     * Determine if a given type is shared.
     *
     * @param string $abstract
     * @return bool
     */
    public function isShared(string $abstract): bool
    {
        $shared = $this->bindings[$abstract]['shared'] ?? self::BIND_NONSHARED;

        return isset($this->instances[$abstract]) || $shared === self::BIND_SHARED;
    }

    /**
     * Make a Closure to be used when building a type.
     *
     * @param string $abstract
     * @param string $concrete
     * @return \Closure
     */
    protected function makeClosure(string $abstract, string $concrete): callable
    {
        return function ($container, $parameters = []) use ($abstract, $concrete) {
            $method = ($abstract == $concrete) ? 'build' : 'make';

            return $container->$method($concrete, $parameters);
        };
    }

    /**
     * Instantiate a concrete instance of a specific type.
     *
     * @param string|\Closure $concrete
     * @param array $parameters
     * @return mixed
     * @throws \Atoms\Container\NotFoundException
     * @throws \Atoms\Container\ContainerException
     */
    protected function build($concrete, array $parameters = [])
    {
        /** If the concrete type is a closure, execute it and return the results */
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException) {
            throw new NotFoundException("The class {$concrete} could not be found");
        }

        if (!$reflector->isInstantiable()) {
            throw new ContainerException("The class {$concrete} is not instantiable");
        }

        $constructor = $reflector->getConstructor();

        /**
         * If there is no constructor, there are no dependencies. In this case,
         * resolve the instance of the object right away without resolving
         * any other types or dependencies out of these containers.
         */
        if (is_null($constructor)) {
            return new $concrete();
        }

        $dependencies = $constructor->getParameters();

        /**
         * Create each of the dependency instances and use the reflection instances
         * to make a new instance of this class, injecting the created dependencies.
         */
        try {
            $instances = $this->resolveDependencies($dependencies, $parameters);
        } catch (RuntimeException $exception) {
            throw new ContainerException(sprintf(
                'Unable to instantiate %s; %s',
                $concrete,
                lcfirst($exception->getMessage())
            ));
        }

        return $reflector->newInstanceArgs($instances);
    }

    /**
     * Resolve all of the dependencies from the ReflectionParameters.
     *
     * @param array $dependencies
     * @param array $parameters
     * @return array
     */
    protected function resolveDependencies(array $dependencies, array $parameters): array
    {
        $instances = [];

        foreach ($dependencies as $dependency) {
            /** Check for a parameter override for this dependency */
            if (array_key_exists($dependency->name, $parameters)) {
                $instances[] = $parameters[$dependency->name];

                continue;
            }

            $instances[] = is_null($this->getParameterClassName($dependency))
                ? $this->resolvePrimitive($dependency)
                : $this->resolveClass($dependency);
        }

        return $instances;
    }

    /**
     * Resolve a class based dependency from the container.
     *
     * @param \ReflectionParameter $parameter
     * @return mixed
     * @throws \Atoms\Container\ContainerException
     */
    protected function resolveClass(ReflectionParameter $parameter)
    {
        try {
            $name = $this->getParameterClassName($parameter);

            if (is_null($name)) {
                throw new RuntimeException("Unresolvable dependency {$parameter->getName()}");
            }

            return $this->make($name);
        } catch (ContainerExceptionInterface $exception) {
            /**
             * If the class instance could not be resolved, check if the value is optional.
             * If so, return the optional parameter value as the value of the dependency.
             */
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            throw $exception;
        }
    }

    /**
     * Resolve a non-class hinted dependency.
     *
     * @param \ReflectionParameter $parameter
     * @return mixed
     * @throws \Atoms\Container\ContainerException
     */
    protected function resolvePrimitive(ReflectionParameter $parameter)
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new RuntimeException("Unresolvable dependency {$parameter->getName()}");
    }

    /**
     * Get the concrete type for the given abstract.
     *
     * @param string $abstract
     * @return mixed
     */
    protected function getConcrete(string $abstract)
    {
        /**
         * If no registered binding was found for the type, assume the type is
         * a concrete name and attempt to resolve it as is since the container
         * should be able to resolve concretes automatically.
         */
        if (array_key_exists($abstract, $this->bindings)) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    /**
     * Returns, if possible, the class name of a speific parameter.
     *
     * @param \ReflectionParameter $parameter
     * @return string|null
     */
    protected function getParameterClassName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return $type->getName();
    }

    /**
     * Determine if the given concrete type is buildable.
     *
     * @param string|\Closure $concrete
     * @param string $abstract
     * @return bool
     */
    protected function isBuildable($concrete, string $abstract): bool
    {
        return $abstract === $concrete || $concrete instanceof Closure;
    }
}
