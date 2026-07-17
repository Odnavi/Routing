<?php

namespace Odnavi\Routing\Service;

use Odnavi\Core\Service\ReflectionFactory;

class ClassScanner
{
    /**
     * @param string $namespace Префикс неймспейса, классы которого сканируются.
     * @param callable $callback Обработчик найденного класса: fn(array $collection, ReflectionClass): array.
     * @param callable|null $filter Отбор по имени класса до рефлексии: fn(string $class): bool.
     */
    public static function scan(string $namespace, callable $callback, ?callable $filter = null)
    {
        global $composer;

        $collection = [];
        foreach ($composer->getClassMap() as $class => $file) {
            if (!str_starts_with($class, $namespace)) {
                continue;
            }

            if ($filter && !$filter($class)) {
                continue;
            }

            $reflection = ReflectionFactory::getClass($class);
            if ($reflection && !$reflection->isTrait() && !$reflection->isAbstract()) {
                $collection = $callback($collection, $reflection);
            }
        }

        return $collection;
    }
}