<?php

declare(strict_types=1);

namespace SyncGuard\Adapters;

/**
 * Class AdapterResolver
 *
 * Resolves adapter class strings (from `channels.adapter_class`) into ChannelAdapter instances.
 *
 * WHY THE RESOLVER WHITELISTS CLASS NAMES:
 * The `adapter_class` column in the database is an arbitrary text string. If user input or an attacker
 * managed to alter the `channels` table, instantiating arbitrary class strings (`new $className()`) could
 * execute arbitrary code (e.g. triggering destructive constructors or deserialization gadgets).
 * To guarantee security, the resolver:
 * 1. Strictly enforces an alphanumeric regex (`^[A-Za-z][A-Za-z0-9]*$`) preventing path traversal or namespace hopping.
 * 2. Restricts resolution strictly to the `SyncGuard\Adapters\` namespace.
 * 3. Asserts that the target class explicitly implements the `ChannelAdapter` interface.
 */
class AdapterResolver
{
    /**
     * @var array<string, ChannelAdapter> Internal cache of instantiated adapters
     */
    private array $instances = [];

    /**
     * @param array<string, ChannelAdapter> $prebuilt Optional pre-configured adapters (useful for dependency injection in tests)
     */
    public function __construct(array $prebuilt = [])
    {
        foreach ($prebuilt as $name => $adapter) {
            $this->instances[$name] = $adapter;
        }
    }

    /**
     * Check if an adapter can be resolved for the given class name.
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return $this->resolve($name) !== null;
    }

    /**
     * Resolve and return a ChannelAdapter instance, or null if invalid or nonexistent.
     *
     * @param string $name
     * @return ChannelAdapter|null
     */
    public function resolve(string $name): ?ChannelAdapter
    {
        // 1. Check prebuilt/cached instances first
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        // 2. Validate class name against strict alphanumeric identifier pattern
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name)) {
            return null;
        }

        // 3. Constrain strictly to the SyncGuard\Adapters\ namespace
        $fullClass = 'SyncGuard\\Adapters\\' . $name;

        if (!class_exists($fullClass)) {
            return null;
        }

        // 4. Require explicit implementation of ChannelAdapter
        if (!is_subclass_of($fullClass, ChannelAdapter::class)) {
            return null;
        }

        $instance = new $fullClass();
        $this->instances[$name] = $instance;

        return $instance;
    }
}
