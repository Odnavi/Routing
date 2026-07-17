<?php

namespace Odnavi\Routing\Controller;

use Api\JsonResponse;
use Odnavi\Core\Service\ReflectionFactory;
use Odnavi\Orm\Attribute\Entity;
use Odnavi\Orm\Entity\{AbstractEntity, Collection};
use Odnavi\Orm\Repository\EntityRepository;
use Odnavi\Orm\Service\RepositoryFactory;
use ReflectionAttribute;
use Odnavi\Routing\Request;
use My\Security\Auth;

abstract class AbstractController
{
    protected string $entityClass;
    protected EntityRepository $repo;

    public function __construct(?EntityRepository $repo = null)
    {
        if ($repo) {
            $this->repo = $repo;
            return;
        }

        isset($this->entityClass) && $this->repo = RepositoryFactory::get($this->entityClass);
    }

    /**
     * Идентификатор текущего пользователя или null, если не аутентифицирован.
     */
    protected function currentUserId(): ?int
    {
        return Auth::get()->getCurrentUserId();
    }

    /**
     * Возвращает идентификатор текущего пользователя.
     *
     * @throws \Exception\UnauthorizedException Если пользователь не аутентифицирован.
     */
    protected function requireUserId(): int
    {
        return Auth::get()->requireUserId();
    }

    /**
     * Проверяет, что ресурс принадлежит текущему пользователю.
     *
     * @throws \Exception\ForbiddenException Если ресурс чужой.
     */
    protected function assertOwner(int $userId): void
    {
        Auth::get()->assertUserId($userId);
    }

    /**
     * Подготавливает коллекцию сущностей к выводу
     *
     * @param Collection $collection
     * @param array $fields
     *
     * @return JsonResponse
     */
    protected function prepareItems(Collection $collection, array $fields): JsonResponse
    {
        return new JsonResponse($collection->toArray());
    }

    /**
     * Подготавливает сущность к выводу
     *
     * @param AbstractEntity $entity
     * @param array|null $fields
     *
     * @return JsonResponse
     */
    protected function prepareItem(AbstractEntity$entity, ?array $fields = null): JsonResponse
    {
        return new JsonResponse($entity->toArray($fields));
    }


    public function preloadRelations(): void
    {
        if (empty($this->collection)) {
            return; // Нет элементов в коллекции
        }

        // Кэш свойств с атрибутом Entity
        $reflection = ReflectionFactory::getClass(reset($this->collection));
        if ($reflection === null) {
            return;
        }
        $properties = $reflection->getProperties();

        // Группируем все сущности по типу
        $relations = [];
        foreach ($properties as $property) {
            $name = $property->getName();
            $attributes = $property->getAttributes(Entity::class);

            if (empty($attributes)) {
                continue;
            }

            /** @var ReflectionAttribute $attribute */
            $attribute = reset($attributes);

            $args = $attribute->getArguments();
            if ($entityClass = $args['class']) {
                $relations[$entityClass] = [
                    'name'        => $name,
                    'foreign_key' => $args['foreignKey'],
                    'setter'      => 'set' . ucfirst($name),
                    'getter'      => 'get' . ucfirst($args['foreignKey'])
                ];
            }
        }

        if (!$relations) {
            return;
        }

        $data = $this->reduce(function (array $carry, AbstractEntity $entity) use ($relations) {
            foreach ($relations as $entityClass => $args) {
                $carry[$entityClass][] = $entity->{$args['getter']}();
            }
            return $carry;
        });

        $relationsData = [];
        // Загружаем сущности одним запросом для каждого типа
        foreach ($relations as $entityClass => $args) {
            !empty($data[$entityClass]) && $relationsData[$entityClass] = RepositoryFactory::get($entityClass)
                ->findAll(['id' => array_filter(array_unique($data[$entityClass]))])
                ->pluck(null, fn($entity) => $entity->getId());
        }

        foreach ($this->collection as $entity) {
            foreach ($relations as $entityClass => $args) {
                $id = $entity->{$args['getter']}();
                if (!empty($relationsData[$entityClass][$id])) {
                    $entity->{$args['setter']}($relationsData[$entityClass][$id]);
                }
            }
        }
    }

    protected function getParameterFields(Request $request): array
    {
        $fields = $request->query->get('fields');
        return empty($fields) ? ['all'] : explode(',', $fields);
    }

    protected function getParameterFilter(Request $request): array
    {
        $filterParam = $request->query->get('filter') ?: '';
        $filterParam = rawurldecode($filterParam);

        $filters = [];
        if ($filterParam) {
            foreach (explode(';', $filterParam) as $item) {
                [$filter, $value] = explode(':', $item);
                $filters[$filter] = $value;
            }
        }

        return $filters;
    }

    protected function getParameterSort(Request $request): array
    {
        $sortParam = $request->query->get('sort') ?: '';
        $sortParam = rawurldecode($sortParam);

        $sort = [];
        if ($sortParam) {
            foreach (explode(';', $sortParam) as $item) {
                [$field, $direction] = explode(':', $item);
                $sort[$field] = $direction;
            }
        }

        return $sort;
    }
}
