<?php

namespace Odnavi\Routing\Contract;

/**
 * DTO формы ответа: строит себя из сущности.
 *
 * Указывается в #[Route(output: ...)]; prepareItem/prepareItems применяют его
 * к сущностям перед сериализацией в JSON. Тип источника намеренно `object` —
 * контракт не завязан на конкретную ORM.
 */
interface OutputDto
{
    /**
     * Создаёт DTO ответа из сущности.
     *
     * @param object $entity Сущность-источник (в текущей связке — ORM AbstractEntity).
     */
    public static function fromEntity(object $entity): static;

    /**
     * Представление для JSON-ответа.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
