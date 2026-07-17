<?php

namespace Odnavi\Routing\Controller;

use Odnavi\Routing\Attribute\Route;

/**
 * Контроллер, которому при диспатче передаётся сам маршрут.
 *
 * Нужен декларативным ресурсам: generic-контроллер получает из Route класс
 * сущности и настраивает под него репозиторий.
 */
interface ResourceAware
{
    /**
     * Привязывает найденный маршрут к контроллеру перед вызовом обработчика.
     */
    public function bindRoute(Route $route): void;
}
