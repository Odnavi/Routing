<?php

namespace Odnavi\Routing\Exception;

use InvalidArgumentException;

/**
 * Ошибка валидации input-DTO. Бросается InputValidator; приложение маппит её в
 * HTTP-ответ (по коду/statusCode в своём обработчике исключений).
 */
class ValidationException extends InvalidArgumentException
{
    /** @var int */
    protected $code = 5;

    /** @var string */
    protected $message = 'Недопустимые параметры запроса.';
}
