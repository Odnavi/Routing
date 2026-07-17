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
    private ?array $preHandler = null;

    private ?string $preHandlerName;

    private array $pathParams = [];
    private array $args       = [];

    public function __construct(
        private readonly ?string $path = null,
        array|string $methods = ['GET'],
        private readonly array $requirements = [],
        ?string $preHandler = null,
        private readonly ?array $rules = null
    ) {
        $this->methods = (array)$methods;
        $this->preHandlerName = $preHandler;
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

    public function getPreHandlerName(): ?string
    {
        return $this->preHandlerName;
    }

    public function setPreHandler(array $value): self
    {
        $this->preHandler = $value;
        return $this;
    }

    public function getPreHandler(): ?array
    {
        return $this->preHandler;
    }

    public function getRequirements(): array
    {
        return $this->requirements;
    }

    public function getRules(): ?array
    {
        return $this->rules;
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
        $preHandler = $this->preHandler;
        $handler    = $this->callback;

        if ($preHandler) {
            (new $preHandler[0])->{$preHandler[1]}($request);
        }

        return (new $handler[0])->{$handler[1]}($request, ...$this->args);
    }
}
