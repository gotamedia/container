<?php

declare(strict_types=1);

namespace Atoms\Container;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;

class Container implements ContainerInterface
{
    /**
     * @var array
     */
    public $instances = [];

    /**
     * @var array
     */
    public $resolved = [];

    /**
     * @var array
     */
    public $bindings = [];

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
     */
    public function has($id)
    {
        if (!is_string($id)) {
            return false;
        }

        if (isset($this->instances[$id])) {
            return true;
        }

        try {
            $reflector = new ReflectionClass($id);
        } catch (ReflectionException $exception) {
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
     * @param string|\Closure $concrete
     * @param bool $shared
     */
    public function bind(string $abstract, $concrete = null, bool $shared = false)
    {
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
    public function share(string $abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Register an existing instance in the container as shared.
     *
     * @param string $abstract
     * @param mixed $instance
     */
    public function instance(string $abstract, $instance)
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
         * If a shared instance of the requested type already has been created,
         * just return the existing instance so it will always be the same one
         * returned.
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
        $object = $this->isBuildable($concrete, $abstract) ?
            $this->build($concrete, $parameters):
            $this->make($concrete, $parameters);

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
        $shared = $this->bindings[$abstract]['shared'] ?? false;

        return isset($this->instances[$abstract]) || $shared;
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
     * Instantiate a concrete instance of the given type.
     *
     * @param string|\Closure $concrete
     * @param array $parameters
     * @return mixed
     * @throws \Atoms\Container\NotFoundException
     * @throws \Atoms\Container\ContainerException
     */
    protected function build($concrete, array $parameters = [])
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $exception) {
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
            return new $concrete;
        }

        $dependencies = $constructor->getParameters();

        /**
         * Create each of the dependency instances and use the reflection instances
         * to make new instance of this class, injecting the created dependencies in.
         */
        $parameters = $this->keyParametersByArgument($dependencies, $parameters);

        $instances = $this->getDependencies($dependencies, $parameters);

        return $reflector->newInstanceArgs($instances);
    }

    /**
     * Resolve all of the dependencies from the ReflectionParameters.
     *
     * @param array $parameters
     * @param array $primitives
     * @return array
     */
    protected function getDependencies(array $parameters, array $primitives = []): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $dependency = $parameter->getClass();

            /**
             * If the class could not be retrieved (null), it means the dependency
             * is a string or other primitive type which is non-resolvable.
             */
            if (array_key_exists($parameter->name, $primitives)) {
                $dependencies[] = $primitives[$parameter->name];
            } elseif (is_null($dependency)) {
                $dependencies[] = $this->resolveNonClass($parameter);
            } else {
                $dependencies[] = $this->resolveClass($parameter);
            }
        }

        return (array)$dependencies;
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
            return $this->make($parameter->getClass()->name);
        } catch (ContainerException $exception) {
            /**
             * If the class instance could not be resolved, check if the value is optional.
             * If so, return the optional parameter value as the value of the dependency.
             */
            if ($parameter->isOptional()) {
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
    protected function resolveNonClass(ReflectionParameter $parameter)
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new ContainerException(
            "Unresolvable dependency resolving {$parameter} in class " .
            $parameter->getDeclaringClass()->getName()
        );
    }

    /**
     * If extra parameters are passed by numeric ID, re-key them by argument name.
     *
     * @param array $dependencies
     * @param array $parameters
     * @return array
     */
    protected function keyParametersByArgument(array $dependencies, array $parameters): array
    {
        foreach ($parameters as $key => $value) {
            if (is_numeric($key)) {
                unset($parameters[$key]);

                $parameters[$dependencies[$key]->name] = $value;
            }
        }

        return $parameters;
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
         * If no registered binding was found for the type, just assume the type
         * is a concrete name and attempt to resolve it as is since the container
         * should be able to resolve concretes automatically.
         */
        if (!isset($this->bindings[$abstract])) {
            return $abstract;
        }

        return $this->bindings[$abstract]['concrete'];
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
