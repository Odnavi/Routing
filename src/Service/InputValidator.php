<?php

namespace Odnavi\Routing\Service;

use Odnavi\Routing\Exception\ValidationException;
use Odnavi\Core\Service\ReflectionFactory;
use Odnavi\Core\Util\StringUtil;
use Odnavi\Routing\Request;
use ReflectionProperty;
use Symfony\Component\Validator\{ConstraintViolationListInterface, Validation};

/**
 * Строит типизированный input-DTO из запроса и валидирует его по атрибутам #[Assert\*].
 *
 * Аналог Symfony MapRequestPayload: тело запроса → public-свойства DTO (ключи
 * сопоставляются в snake_case и camelCase), затем symfony/validator.
 */
final class InputValidator
{
    /**
     * Гидрирует и валидирует DTO входа. Бросает исключение при нарушениях.
     *
     * @param class-string $dtoClass
     *
     * @throws ValidationException При ошибках валидации.
     */
    public static function create(string $dtoClass, Request $request): object
    {
        $dto = self::hydrate($dtoClass, self::readData($request));
        self::validate($dto);

        return $dto;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readData(Request $request): array
    {
        $body = json_decode($request->getContent(), true);

        return is_array($body) ? $body : [];
    }

    /**
     * Заполняет public-свойства DTO из данных (snake_case и camelCase ключи).
     *
     * @param class-string         $dtoClass
     * @param array<string, mixed> $data
     */
    public static function hydrate(string $dtoClass, array $data): object
    {
        $reflection = ReflectionFactory::getClass($dtoClass);
        $dto        = $reflection->newInstanceWithoutConstructor();

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name  = $property->getName();
            $snake = StringUtil::toSnakeCase($name);

            $key = array_key_exists($name, $data) ? $name
                : (array_key_exists($snake, $data) ? $snake : null);

            if ($key !== null) {
                $property->setValue($dto, $data[$key]);
            }
        }

        return $dto;
    }

    /**
     * @throws ValidationException При нарушениях валидации.
     */
    public static function validate(object $dto): void
    {
        $validator  = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = $validator->validate($dto);

        if ($violations->count() > 0) {
            throw new ValidationException(self::format($violations));
        }
    }

    private static function format(ConstraintViolationListInterface $violations): string
    {
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
        }

        return implode('; ', $messages);
    }
}
