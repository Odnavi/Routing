<?php

namespace Odnavi\Routing\Attribute;

/**
 * Базовый атрибут авто-операции ресурса.
 *
 * Наследник Route: переиспользует всю механику маршрута (group, requirements,
 * preHandle-гвард, input/output, path-параметры, handle), добавляя целевую сущность,
 * имя generic-обработчика и имена хуков. Конкретные операции (Get, Post и т.д.)
 * задают дефолты через константы.
 */
abstract class Operation extends Route
{
    /** HTTP-метод операции. */
    protected const METHOD = 'GET';

    /** Относительный путь операции (дополняет группу). */
    protected const PATH = '';

    /** Требования к path-параметрам. */
    protected const REQUIREMENTS = [];

    /** Generic-метод-обработчик в AbstractController. */
    protected const HANDLER = '';

    /** Имя хука до операции по соглашению (null — без хука). */
    protected const HOOK_BEFORE = null;

    /** Имя хука после операции по соглашению (null — без хука). */
    protected const HOOK_AFTER = null;

    /**
     * @param string  $entity     Класс целевой сущности.
     * @param ?string $input      DTO входа (валидация); для read-операций не нужен.
     * @param ?string $output     DTO выхода (форма ответа).
     * @param ?string $preHandle  Гвард до операции: метод контроллера или callable-строка.
     * @param ?string $beforeHook Переопределение имени хука до операции.
     * @param ?string $afterHook  Переопределение имени хука после операции.
     * @param bool    $paginated  Отдавать список с пагинацией (актуально для GetCollection).
     */
    public function __construct(
        string  $entity,
        ?string $input = null,
        ?string $output = null,
        ?string $preHandle = null,
        ?string $beforeHook = null,
        ?string $afterHook = null,
        bool    $paginated = false
    ) {
        parent::__construct(
            path: static::PATH,
            methods: static::METHOD,
            requirements: static::REQUIREMENTS,
            preHandle: $preHandle,
            input: $input,
            output: $output
        );

        $this->entity     = $entity;
        $this->handler    = static::HANDLER;
        $this->hookBefore = $beforeHook ?? static::HOOK_BEFORE;
        $this->hookAfter  = $afterHook ?? static::HOOK_AFTER;
        $this->paginated  = $paginated;
    }
}
