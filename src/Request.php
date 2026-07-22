<?php

namespace Odnavi\Routing;

/**
 * HTTP-запрос: тонкая обёртка над суперглобалами ($_GET, $_SERVER, php://input).
 * Заменяет symfony/http-foundation в объёме, нужном роутингу.
 */
class Request
{
    /**
     * @param ParameterBag $query   Параметры строки запроса ($_GET).
     * @param ParameterBag $cookies Cookie запроса ($_COOKIE).
     * @param ParameterBag $headers Заголовки запроса.
     * @param string       $method  HTTP-метод в верхнем регистре.
     * @param string       $pathInfo Путь запроса без query-строки.
     * @param string       $content  Сырое тело запроса.
     */
    public function __construct(
        public readonly ParameterBag $query,
        public readonly ParameterBag $cookies,
        public readonly ParameterBag $headers,
        private readonly string $method,
        private readonly string $pathInfo,
        private readonly string $content,
    ) {
    }

    /**
     * Собирает запрос из текущих суперглобалов.
     */
    public static function createFromGlobals(): static
    {
        return new static(
            new ParameterBag($_GET),
            new ParameterBag($_COOKIE),
            new ParameterBag(self::readHeaders()),
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            self::readPathInfo(),
            file_get_contents('php://input') ?: '',
        );
    }

    /**
     * HTTP-метод запроса.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Путь запроса без query-строки (например, /v1/accounts).
     */
    public function getPathInfo(): string
    {
        return $this->pathInfo;
    }

    /**
     * Сырое тело запроса.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Извлекает путь из REQUEST_URI, отбрасывая query-строку.
     */
    private static function readPathInfo(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return is_string($path) && $path !== '' ? rawurldecode($path) : '/';
    }

    /**
     * Читает заголовки запроса (getallheaders либо разбор $_SERVER['HTTP_*']).
     *
     * @return array<string, string>
     */
    private static function readHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name           = ucwords(strtolower(str_replace('_', ' ', substr($key, 5))));
                $headers[str_replace(' ', '-', $name)] = $value;
            }
        }

        return $headers;
    }
}
