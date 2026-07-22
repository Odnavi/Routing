<?php

namespace Odnavi\Routing\Dto;

use Odnavi\Core\Contract\{Entity, EntityCollection};

/**
 * Рантайм-обёртка списка: элементы плюс метаданные пагинации.
 * Форма ответа: { items, pagination }.
 */
final class PaginatedResponse
{
    /**
     * @param array<int, array> $items      Сериализованные элементы списка.
     * @param array{page: int, limit: int|null, total: int, pages: int} $pagination
     */
    public function __construct(
        public readonly array $items,
        public readonly array $pagination,
    ) {
    }

    /**
     * Собирает ответ из коллекции, сериализуя каждый элемент DTO ($itemDto::fromEntity()->toArray()).
     *
     * @param EntityCollection $collection Коллекция с посчитанным total.
     * @param class-string     $itemDto    Класс DTO элемента (fromEntity + toArray).
     * @param int              $page       Текущая страница (с единицы).
     * @param int|null         $limit      Размер страницы; null — без ограничения.
     */
    public static function fromCollection(EntityCollection $collection, string $itemDto, int $page, ?int $limit): self
    {
        $total = $collection->getTotal();

        return new self(
            items: $collection->toArray(
                static fn(Entity $entity): array => $itemDto::fromEntity($entity)->toArray()
            ),
            pagination: [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $limit ? (int) ceil($total / $limit) : 1,
            ],
        );
    }

    /**
     * @return array{items: array, pagination: array}
     */
    public function toArray(): array
    {
        return [
            'items'      => $this->items,
            'pagination' => $this->pagination,
        ];
    }
}
