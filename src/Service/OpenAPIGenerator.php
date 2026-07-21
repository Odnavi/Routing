<?php

namespace Odnavi\Routing\Service;

use Odnavi\Core\Service\ReflectionFactory;
use ReflectionParameter;

class OpenAPIGenerator
{
    public static function generate(): array
    {
        $routes = RouteFactory::getAllRoutes();
        
        $paths = [];
        $componentsSchemas = [];
        
        foreach ($routes as $route) {
            $path = $route['path'];
            $methods = $route['methods'];
            $requirements = $route['requirements'] ?? [];
            $handler = $route['handler'] ?? null;
            
            // Пропускаем роут документации
            if ($path === '/api/v1') {
                continue;
            }
            
            // Парсим параметры пути
            $pathParams = self::extractPathParams($path, $requirements);
            
            foreach ($methods as $method) {
                $method = strtoupper($method);
                
                if (!isset($paths[$path])) {
                    $paths[$path] = [];
                }
                
                $operation = [
                    'summary' => self::generateSummary($handler, $method),
                    'operationId' => self::generateOperationId($path, $method),
                    'tags' => self::extractTags($handler, $path),
                ];
                
                // Параметры пути
                if (!empty($pathParams)) {
                    $operation['parameters'] = $pathParams;
                }
                
                // Query параметры для GET запросов
                if ($method === 'GET') {
                    $queryParams = self::extractQueryParams($handler);
                    if (!empty($queryParams)) {
                        $operation['parameters'] = array_merge(
                            $operation['parameters'] ?? [],
                            $queryParams
                        );
                    }
                }
                
                // Request body для POST/PATCH
                if (in_array($method, ['POST', 'PATCH', 'PUT'])) {
                    $operation['requestBody'] = [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => self::extractRequestBodySchema($handler),
                                ],
                            ],
                        ],
                    ];
                }
                
                // Responses
                $operation['responses'] = self::buildResponses($handler, $componentsSchemas);
                
                // Security для роутов, требующих авторизации
                if (self::requiresAuth($handler)) {
                    $operation['security'] = [
                        ['bearerAuth' => []],
                    ];
                }
                
                $paths[$path][strtolower($method)] = $operation;
            }
        }
        
        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Money API',
                'version' => '1.0.0',
                'description' => 'API для управления личными финансами',
            ],
            'servers' => [
                [
                    'url' => '/api/v1',
                    'description' => 'Production server',
                ],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                ],
                'schemas' => $componentsSchemas,
            ],
        ];
    }

    private static function buildResponses(?array $handler, array &$componentsSchemas): array
    {
        $successSchema = ['type' => 'object'];

        if ($handler) {
            [$className, $methodName] = $handler;

            // Схема ответа из рантайм-DTO контроллера ($responseDtoClass) — единый источник правды.
            $responseDto = self::resolveControllerResponseDto($className);

            if ($responseDto) {
                $schemaName = self::schemaNameFromClass($responseDto);
                $componentsSchemas[$schemaName] ??= $responseDto::schema();
                $itemRef = ['$ref' => '#/components/schemas/' . $schemaName];

                $successSchema = match ($methodName) {
                    'listHandler'   => self::paginatedSchema($itemRef),
                    'deleteHandler' => self::successSchema(),
                    default         => $itemRef,
                };
            } elseif ($methodName === 'deleteHandler') {
                $successSchema = self::successSchema();
            } else {
                // Фолбэк: схемные классы по сущности (старое поведение).
                $entityClass = self::resolveEntityClass($className);

                if ($entityClass && in_array($methodName, ['listHandler', 'readHandler'])) {
                    $dtoClass = self::resolveResponseDtoClass($handler, $methodName);

                    if ($dtoClass && method_exists($dtoClass, 'schema')) {
                        $schemaName = self::schemaNameFromClass($entityClass)
                            . ($methodName === 'listHandler' ? 'ListResponse' : 'ReadResponse');

                        $componentsSchemas[$schemaName] = $dtoClass::schema($entityClass);
                        $successSchema = ['$ref' => '#/components/schemas/' . $schemaName];
                    }
                }
            }
        }

        return [
            '200' => [
                'description' => 'Успешный ответ',
                'content' => [
                    'application/json' => [
                        'schema' => $successSchema,
                    ],
                ],
            ],
            '400' => ['description' => 'Неверный запрос'],
            '401' => ['description' => 'Не авторизован'],
            '404' => ['description' => 'Не найдено'],
            '500' => ['description' => 'Внутренняя ошибка сервера'],
        ];
    }

    private static function resolveResponseDtoClass(array $handler, string $methodName): ?string
    {
        [$controllerClass] = $handler;

        $dtoName = $methodName === 'listHandler' ? 'ListResponse' : 'ReadResponse';

        $namespace = substr($controllerClass, 0, strrpos($controllerClass, '\\'));
        $candidate = "$namespace\\Dto\\Response\\$dtoName";
        if (class_exists($candidate)) {
            return $candidate;
        }

        $coreCandidate = "Core\\Controller\\Api\\Dto\\Response\\$dtoName";
        if (class_exists($coreCandidate)) {
            return $coreCandidate;
        }

        return null;
    }

    /**
     * Возвращает класс рантайм-DTO ответа контроллера ($responseDtoClass), если он задан.
     *
     * @return class-string|null
     */
    private static function resolveControllerResponseDto(string $controllerClass): ?string
    {
        try {
            $reflection = ReflectionFactory::getClass($controllerClass);
            if (!$reflection || !$reflection->hasProperty('responseDtoClass')) {
                return null;
            }

            $dtoClass = $reflection->getDefaultProperties()['responseDtoClass'] ?? null;

            return is_string($dtoClass)
                && class_exists($dtoClass)
                && method_exists($dtoClass, 'fromEntity')
                    ? $dtoClass
                    : null;
        } catch (\ReflectionException) {
            return null;
        }
    }

    /**
     * Оборачивает схему элемента в форму ответа списка: { items, pagination }.
     */
    private static function paginatedSchema(array $itemRef): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type'  => 'array',
                    'items' => $itemRef,
                ],
                'pagination' => [
                    'type' => 'object',
                    'properties' => [
                        'page'  => ['type' => 'integer', 'example' => 1],
                        'limit' => ['type' => 'integer', 'nullable' => true, 'example' => 20],
                        'total' => ['type' => 'integer', 'example' => 105],
                        'pages' => ['type' => 'integer', 'example' => 6],
                    ],
                ],
            ],
        ];
    }

    /**
     * Схема ответа-подтверждения: { success: true }.
     */
    private static function successSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean', 'example' => true],
            ],
        ];
    }

    private static function resolveEntityClass(string $controllerClass): ?string
    {
        try {
            $reflection = ReflectionFactory::getClass($controllerClass);
            if (!$reflection || !$reflection->hasProperty('entityClass')) {
                return null;
            }

            $defaults = $reflection->getDefaultProperties();
            $entityClass = $defaults['entityClass'] ?? null;

            return is_string($entityClass) && class_exists($entityClass)
                ? $entityClass
                : null;
        } catch (\ReflectionException) {
            return null;
        }
    }

    private static function schemaNameFromClass(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }

    private static function extractPathParams(string $path, array $requirements): array
    {
        $params = [];
        preg_match_all('/\{(\w+)\}/', $path, $matches);
        
        foreach ($matches[1] as $paramName) {
            $param = [
                'name' => $paramName,
                'in' => 'path',
                'required' => true,
                'schema' => [
                    'type' => 'string',
                ],
            ];
            
            // Если есть требования, используем их
            if (isset($requirements[$paramName])) {
                $req = $requirements[$paramName];
                if ($req === '\d+') {
                    $param['schema']['type'] = 'integer';
                    $param['schema']['format'] = 'int64';
                } else {
                    $param['schema']['pattern'] = $req;
                }
            }
            
            $params[] = $param;
        }
        
        return $params;
    }
    
    private static function extractQueryParams(?array $handler): array
    {
        if (!$handler) {
            return [];
        }
        
        [$className, $methodName] = $handler;
        
        try {
            $reflection = ReflectionFactory::getClass($className);
            if (!$reflection) {
                return [];
            }

            $method = $reflection->getMethod($methodName);

            $params = [];
            foreach ($method->getParameters() as $param) {
                // Пропускаем Request параметр
                if ($param->getType() && $param->getType()->getName() === 'Request') {
                    continue;
                }
                
                // Если это параметр пути, пропускаем
                if ($param->getName() === 'id' || $param->getName() === 'slug') {
                    continue;
                }
                
                $paramDef = [
                    'name' => $param->getName(),
                    'in' => 'query',
                    'required' => !$param->isOptional(),
                    'schema' => [
                        'type' => self::getParameterType($param),
                    ],
                ];
                
                if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                    $paramDef['schema']['default'] = $param->getDefaultValue();
                }
                
                $params[] = $paramDef;
            }
            
            // Стандартные query параметры для списков
            $commonParams = [
                [
                    'name' => 'fields',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'string'],
                    'description' => 'Список полей через запятую',
                ],
                [
                    'name' => 'filter',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'string'],
                    'description' => 'Фильтры в формате field:value;field2:value2',
                ],
                [
                    'name' => 'sort',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'string'],
                    'description' => 'Сортировка в формате field:asc;field2:desc',
                ],
                [
                    'name' => 'limit',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'integer'],
                    'description' => 'Лимит записей',
                ],
                [
                    'name' => 'page',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'integer'],
                    'description' => 'Номер страницы',
                ],
            ];
            
            return array_merge($params, $commonParams);
        } catch (\ReflectionException $e) {
            return [];
        }
    }
    
    private static function extractRequestBodySchema(?array $handler): array
    {
        if (!$handler) {
            return [];
        }
        
        [$className] = $handler;
        
        try {
            $properties = [];
            
            // Пытаемся найти entityClass в контроллере
            $entityClass = self::resolveEntityClass($className);
            if ($entityClass) {
                $entityReflection = ReflectionFactory::getClass($entityClass);
                foreach ($entityReflection?->getProperties() ?? [] as $prop) {
                    if ($prop->isPublic() || $prop->isProtected()) {
                        $properties[self::toSnakeCase($prop->getName())] = self::mapRequestBodyPropertySchema($prop);
                    }
                }
            }
            
            return $properties;
        } catch (\ReflectionException $e) {
            return [];
        }
    }
    
    private static function mapRequestBodyPropertySchema(\ReflectionProperty $property): array
    {
        $type = $property->getType();
        if (!$type) {
            return ['type' => 'string'];
        }

        $typeName = $type->getName();

        $schema = match ($typeName) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number', 'format' => 'float'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            default => class_exists($typeName) ? ['type' => 'object'] : ['type' => 'string'],
        };

        if ($type->allowsNull()) {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    private static function toSnakeCase(string $value): string
    {
        $snakeCase = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);
        return strtolower($snakeCase ?? $value);
    }

    private static function getParameterType(ReflectionParameter $param): string
    {
        $type = $param->getType();
        if (!$type) {
            return 'string';
        }
        
        $typeName = $type->getName();
        
        return match ($typeName) {
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            'array' => 'array',
            default => 'string',
        };
    }
    
    private static function generateSummary(?array $handler, string $method): string
    {
        if (!$handler) {
            return ucfirst(strtolower($method)) . ' request';
        }
        
        [$className, $methodName] = $handler;
        
        // Убираем суффиксы Handler
        $summary = str_replace(['Handler', 'list', 'create', 'read', 'update', 'delete'], '', $methodName);
        $summary = preg_replace('/([A-Z])/', ' $1', $summary);
        $summary = trim($summary);
        
        if (empty($summary)) {
            $summary = ucfirst(strtolower($method)) . ' operation';
        }
        
        return $summary;
    }
    
    private static function generateOperationId(string $path, string $method): string
    {
        $path = trim($path, '/');
        $path = str_replace(['/', '{', '}'], ['_', '', ''], $path);
        $path = preg_replace('/[^a-zA-Z0-9_]/', '_', $path);
        
        return strtolower($method) . '_' . $path;
    }
    
    private static function extractTags(?array $handler, string $path): array
    {
        if ($handler) {
            [$className] = $handler;
            $parts = explode('\\', trim($className, '\\'));
            $controllerIndex = array_search('Controller', $parts, true);

            if ($controllerIndex !== false) {
                for ($i = $controllerIndex + 1; $i < count($parts); $i++) {
                    if (!preg_match('/^V\d+$/i', $parts[$i]) && $parts[$i] !== '') {
                        return [ucfirst($parts[$i])];
                    }
                }
            }
        }

        $parts = explode('/', trim($path, '/'));
        if (count($parts) >= 3) {
            return [ucfirst($parts[2])];
        }

        return ['Api'];
    }
    
    private static function requiresAuth(?array $handler): bool
    {
        if (!$handler) {
            return false;
        }
        
        [$className, $methodName] = $handler;
        
        try {
            $reflection = ReflectionFactory::getClass($className);
            if (!$reflection) {
                return false;
            }

            // Проверяем наличие preHandler с assertAuthorization
            if ($reflection->hasMethod('listPreHandler')) {
                return true;
            }
            
            // Проверяем метод на явный вызов проверки авторизации
            if ($reflection->hasMethod($methodName)) {
                $method = $reflection->getMethod($methodName);
                $code = file_get_contents($method->getFileName());
                $startLine = $method->getStartLine();
                $endLine = $method->getEndLine();
                $methodCode = implode('', array_slice(explode("\n", $code), $startLine - 1, $endLine - $startLine + 1));

                if (strpos($methodCode, 'requireUserId') !== false) {
                    return true;
                }
            }
            
            return false;
        } catch (\ReflectionException $e) {
            return false;
        }
    }
}
