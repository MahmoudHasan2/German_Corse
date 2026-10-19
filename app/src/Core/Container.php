<?php

declare(strict_types=1);

namespace GermanCoach\Core;

/** Minimal service container: lazily built shared services, overridable in tests. */
final class Container
{
    /** @var array<string,\Closure(self):mixed> */
    private array $factories = [];
    /** @var array<string,mixed> */
    private array $instances = [];
    /** @var list<string> services that depend on per-request state (session, current user) */
    private array $requestScoped = [];

    /** @param \Closure(self):mixed $factory */
    public function set(string $id, \Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        if (class_exists($id)) {
            return $this->instances[$id] = $this->autowire($id);
        }
        throw new \RuntimeException("Service not found: $id");
    }

    public function markRequestScoped(string ...$ids): void
    {
        array_push($this->requestScoped, ...$ids);
    }

    /** Drops request-scoped instances so a new request never sees the previous request's session/user. */
    public function resetRequestScope(): void
    {
        foreach ($this->requestScoped as $id) {
            unset($this->instances[$id]);
        }
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }

    /** Constructor injection by class type for application classes. */
    private function autowire(string $class): object
    {
        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return new $class();
        }
        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
                continue;
            }
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }
            throw new \RuntimeException("Cannot autowire parameter \${$param->getName()} of $class");
        }
        return $ref->newInstanceArgs($args);
    }
}
