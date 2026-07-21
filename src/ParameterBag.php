<?php

namespace Odnavi\Routing;

/**
 * Контейнер параметров запроса (query, заголовки) с безопасным доступом по ключу.
 * Заменяет symfony/http-foundation ParameterBag в объёме, нужном роутингу.
 */
final readonly class ParameterBag
{
    /** @param array<string, mixed> $parameters */
    public function __construct(private array $parameters = [])
    {
    }

    /**
     * Возвращает значение параметра или значение по умолчанию, если ключа нет.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }

    /**
     * Проверяет наличие параметра.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->parameters);
    }

    /**
     * Возвращает все параметры.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->parameters;
    }
}
