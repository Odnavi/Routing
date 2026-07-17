<?php

namespace Odnavi\Routing\Service;

use Odnavi\Core\Service\{AttributeReader};
use My\Cache;
use ReflectionClass;
use ReflectionException;
use Odnavi\Routing\Request;
use Odnavi\Routing\Attribute\Route;

final class RouteFactory
{
    /** @var Route[] $routes */
    private static array $routes = [];

    /** @var string[] неймспейсы, в которых искать классы с роутами */
    private static array $namespaces = [];

    /**
     * Регистрирует неймспейс, классы которого нужно просканировать на роуты.
     */
    public static function registerNamespace(string $namespace): void
    {
        self::$namespaces[] = $namespace;
    }

    public static function load(): void
    {
        $cache  = Cache::getInstance();
        $cached = $cache->get('route_map');
        // Кэш валиден, только если это массив настоящих Route. После переезда
        // класса (например, Core\Attribute\Route → Routing\Attribute\Route)
        // старый сериализованный кэш разворачивается в __PHP_Incomplete_Class —
        // такой игнорируем и пересобираем карту (кэш перезапишется ниже).
        if (is_array($cached) && ($cached === [] || current($cached) instanceof Route)) {
            self::$routes = $cached;
            return;
        }

        self::$routes = [];

        foreach (self::$namespaces as $namespace) {
            ClassScanner::scan($namespace, function ($collection, ReflectionClass $reflection) {
                $group = '';

                /** @var Route[] $classRoutes */
                $classRoutes = AttributeReader::getForClass($reflection, Route::class);
                if ($classRoutes) {
                    $group = $classRoutes[0]->getPath();
                }

                foreach (AttributeReader::getForMethods($reflection, attributeClass: Route::class) as ['method' => $method, 'attrs' => $attrs]) {
                    /** @var Route $route */
                    foreach ($attrs as $route) {
                        $group && $route->setGroup($group);

                        $preHandlerName = $route->getPreHandlerName();
                        if ($preHandlerName) {
                            try {
                                preg_match_all('/\{(\w+)\}/', $route->getPath(), $matches);
                                $route
                                    ->setPathParams($matches[1])
                                    ->setPreHandler([$reflection->getName(), $reflection->getMethod($preHandlerName)->getName()]);
                            } catch (ReflectionException) {}
                        }

                        self::$routes[] = $route;
                        $collection[] = $route->setCallback([$reflection->getName(), $method->getName()]);
                    }
                }

                return $collection;
            }, static fn(string $class): bool => str_ends_with($class, 'Controller'));
        }

        $cache->set('route_map', self::$routes);
    }

    public static function get(Request $request): ?Route
    {
        $requestPath   = $request->getPathInfo();
        $requestMethod = $request->getMethod();

        \Odnavi\Core\Profiler::startTimer('search route');
        self::load();

        $found = null;
        foreach (self::$routes as $route) {
            $path = $route->getPattern();
            if (!in_array($requestMethod, $route->getMethods())) {
                continue;
            }

            if ($path === $requestPath) {
                $found = $route;
                break;
            }

            if ($args = self::isPathMatch($requestPath, $path, $route->getRequirements())) {
                $found = $route->setArgs($args);
                break;
            }
        }

        \Odnavi\Core\Profiler::stopTimer();
        return $found;
    }

    private static function getRoutes(): array
    {
        $routes = [];
        foreach (self::$routes as $route) {
            $routes[] = [
                'path'         => $route->getGroup() . $route->getPath(),
                'methods'      => $route->getMethods(),
                'requirements' => $route->getRequirements(),
                'rules'        => $route->getRules(),
                'pre_handler'  => $route->getPreHandler(),
                'handler'      => $route->getCallback(),
            ];
        }

        return $routes;
    }

    public static function getAllRoutes(): array
    {
        return self::getRoutes();
    }

    private static function isPathMatch(string $requestPath, string $routePath, array $requirements): array
    {
        $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_-]*)}/', function ($matches) use ($requirements) {
            $paramName = $matches[1];
            $regex     = $requirements[$paramName] ?? '[^\/]+'; // Если нет требования, любой символ кроме "/"
            return "(?P<$paramName>$regex)";
        }, $routePath);

        $params = [];
        if (!preg_match( "~^$pattern$~", $requestPath, $matches)) {
            return $params;
        }

        foreach ($matches as $key => $value) {
            !is_int($key) && $params[$key] = $value;
        }

        return $params;
    }
}
