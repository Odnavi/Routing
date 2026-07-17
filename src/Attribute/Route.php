<?php

namespace Odnavi\Routing\Attribute;

use Attribute;
use Odnavi\Routing\Request;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Route
{
    private array   $methods;
    private ?string $group = null;

    private array  $callback;

    /** Резолвнутый гвард preHandle: [класс, метод] (метод контроллера) либо callable-строка. */
    private array|string|null $preHandle = null;

    /** Значение preHandle из атрибута: имя метода контроллера или callable-строка. */
    private ?string $preHandleName;

    private array $pathParams = [];
    private array $args       = [];

    /** Отдавать список с метаданными пагинации ({items, pagination}) — только для GetCollection. */
    protected bool $paginated = false;

    /** Класс сущности (для операций-подклассов); задаёт репозиторий в bindRoute. */
    protected ?string $entity = null;

    /** Имя generic-метода-обработчика (для операций-подклассов). */
    protected ?string $handler = null;

    /** Имя метода-хука до операции (разрезолвлено: override ?? соглашение). */
    protected ?string $hookBefore = null;

    /** Имя метода-хука после операции. */
    protected ?string $hookAfter = null;

    /**
     * @param ?string $path      Путь маршрута (на классе — префикс-группа).
     * @param ?string $preHandle Гвард до обработчика: имя метода контроллера либо
     *                           callable-строка ('Class::method' / глобальная функция).
     * @param ?string $input     DTO входа: типизированные поля + валидация (#[Assert\*]).
     * @param ?string $output    DTO выхода: форма ответа (реализует OutputDto::fromEntity).
     */
    public function __construct(
        private readonly ?string $path = null,
        array|string $methods = ['GET'],
        private readonly array $requirements = [],
        ?string $preHandle = null,
        private readonly ?string $input = null,
        private readonly ?string $output = null
    ) {
        $this->methods       = (array)$methods;
        $this->preHandleName = $preHandle;
    }

    public function isPaginated(): bool
    {
        return $this->paginated;
    }

    public function getInput(): ?string
    {
        return $this->input;
    }

    public function getOutput(): ?string
    {
        return $this->output;
    }

    public function getEntity(): ?string
    {
        return $this->entity;
    }

    public function getHandler(): ?string
    {
        return $this->handler;
    }

    public function getHookBefore(): ?string
    {
        return $this->hookBefore;
    }

    public function getHookAfter(): ?string
    {
        return $this->hookAfter;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getPattern(): string
    {
        return $this->getGroup() . $this->getPath();
    }

    public function setCallback(array $value): self
    {
        $this->callback = $value;
        return $this;
    }

    public function getCallback(): array
    {
        return $this->callback;
    }

    public function setGroup(string $value): self
    {
        $this->group = $value;
        return $this;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function getMethods(): array
    {
        return $this->methods;
    }

    public function getPreHandleName(): ?string
    {
        return $this->preHandleName;
    }

    public function setPreHandle(array|string $value): self
    {
        $this->preHandle = $value;
        return $this;
    }

    public function getPreHandle(): array|string|null
    {
        return $this->preHandle;
    }

    public function getRequirements(): array
    {
        return $this->requirements;
    }

    public function setPathParams(array $params): self
    {
        $this->pathParams = $params;
        return $this;
    }

    public function getPathParams(): array
    {
        return $this->pathParams;
    }

    public function setArgs(array $args): self
    {
        $this->args = $args;
        return $this;
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function handle(Request $request): mixed
    {
        $preHandle = $this->preHandle;
        $handler   = $this->callback;

        if ($preHandle) {
            is_array($preHandle)
                ? (new $preHandle[0])->{$preHandle[1]}($request) // метод контроллера
                : $preHandle($request);                          // callable-строка
        }

        $controller = new $handler[0];
        if ($controller instanceof \Odnavi\Routing\Controller\ResourceAware) {
            $controller->bindRoute($this);
        }

        // Без input-DTO — прежнее поведение: $request + именованные path-параметры.
        if (!$this->input) {
            return $controller->{$handler[1]}($request, ...$this->args);
        }

        $input = \Odnavi\Routing\Service\InputValidator::create($this->input, $request);
        if ($controller instanceof \Odnavi\Routing\Controller\AbstractController) {
            $controller->setInput($input);
        }

        return $controller->{$handler[1]}(...$this->resolveArguments($handler, $request, $input));
    }

    /**
     * Собирает именованные аргументы обработчика: Request и input-DTO по типу,
     * path-параметры по имени.
     *
     * @param array{0: class-string, 1: string} $handler
     *
     * @return array<string, mixed>
     */
    private function resolveArguments(array $handler, Request $request, object $input): array
    {
        $resolved = [];

        foreach ((new \ReflectionMethod($handler[0], $handler[1]))->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType() instanceof \ReflectionNamedType ? $parameter->getType()->getName() : null;

            if ($type === Request::class || is_a($request, (string) $type)) {
                $resolved[$name] = $request;
            } elseif ($type === $this->input || ($type && $input instanceof $type)) {
                $resolved[$name] = $input;
            } elseif (array_key_exists($name, $this->args)) {
                $resolved[$name] = $this->args[$name];
            }
        }

        return $resolved;
    }
}
