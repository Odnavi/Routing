<?php

namespace Odnavi\Routing;

/**
 * JSON-ответ: сериализует данные и отправляет их с заголовком application/json.
 * Заменяет symfony/http-foundation JsonResponse в объёме, нужном роутингу.
 */
class JsonResponse
{
    /**
     * @param mixed                 $data    Данные ответа (сериализуются в JSON).
     * @param int                   $status  HTTP-статус.
     * @param array<string, string> $headers Дополнительные заголовки.
     */
    public function __construct(
        private readonly mixed $data = null,
        private int $status = 200,
        private readonly array $headers = [],
    ) {}

    /**
     * Устанавливает HTTP-статус ответа.
     */
    public function setStatusCode(int $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * HTTP-статус ответа.
     */
    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * Сериализованное тело ответа.
     */
    public function getContent(): string
    {
        return (string) json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Отправляет статус, заголовки и тело ответа клиенту.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
            foreach ($this->headers as $name => $value) {
                header("$name: $value");
            }
        }

        echo $this->getContent();
    }
}
