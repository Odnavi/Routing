<?php

namespace Odnavi\Routing\Controller;

use Odnavi\Routing\JsonResponse;
use Odnavi\Core\Contract\{Entity, EntityCollection, Repository};
use Odnavi\Core\DbRegistry;
use Odnavi\Core\RepositoryRegistry;
use Odnavi\Core\Util\StringUtil;
use Odnavi\Routing\Request;
use Odnavi\Routing\Attribute\Route;
use Odnavi\Routing\Dto\PaginatedResponse;

abstract class AbstractController implements ResourceAware
{
    protected string $entityClass;
    protected Repository $repo;

    /** Маршрут, по которому вызван контроллер (доступен после диспатча). */
    protected ?Route $route = null;

    /** Валидированный input-DTO текущего запроса (если задан #[Route(input: ...)]). */
    protected ?object $input = null;

    public function __construct(?Repository $repo = null)
    {
        if ($repo) {
            $this->repo = $repo;
            return;
        }

        isset($this->entityClass) && $this->repo = RepositoryRegistry::get($this->entityClass);
    }

    /**
     * Привязывает найденный маршрут к контроллеру перед вызовом обработчика.
     *
     * Для авто-операций (#[Get]/#[Post]/...) маршрут несёт целевую сущность —
     * репозиторий настраивается под неё автоматически. Также нужен для доступа
     * к output-DTO в prepareItem/prepareItems.
     */
    public function bindRoute(Route $route): void
    {
        $this->route = $route;

        if ($entity = $route->getEntity()) {
            $this->entityClass = $entity;
            $this->repo        = RepositoryRegistry::get($entity);
        }
    }

    /**
     * Передаёт валидированный input-DTO в контроллер (вызывается диспатчем).
     */
    public function setInput(object $input): void
    {
        $this->input = $input;
    }

    /**
     * Подготавливает коллекцию сущностей к выводу
     *
     * @param EntityCollection $collection
     * @param array $fields
     *
     * @return JsonResponse
     */
    protected function prepareItems(EntityCollection $collection, array $fields): JsonResponse
    {
        if ($output = $this->route?->getOutput()) {
            return new JsonResponse($collection->map(static fn(Entity $entity) => $output::fromEntity($entity)->toArray()));
        }

        return new JsonResponse($collection->toArray());
    }

    /**
     * Подготавливает сущность к выводу
     *
     * @param Entity $entity
     * @param array|null $fields
     *
     * @return JsonResponse
     */
    protected function prepareItem(Entity $entity, ?array $fields = null): JsonResponse
    {
        if ($output = $this->route?->getOutput()) {
            return new JsonResponse($output::fromEntity($entity)->toArray());
        }

        return new JsonResponse($entity->toArray($fields));
    }

    /**
     * Декодирует JSON-тело запроса в массив полей.
     *
     * @return array<string, mixed>
     */
    protected function parseBody(Request $request): array
    {
        return json_decode($request->getContent(), true) ?: [];
    }

    /**
     * Заполняет сущность значениями полей через сеттеры (snake_case → setCamelCase).
     *
     * @param array<string, mixed> $fields
     */
    protected function fillEntity(Entity $entity, array $fields): void
    {
        foreach ($fields as $field => $value) {
            $setter = 'set' . StringUtil::toCamelCase($field, true);
            $entity->{$setter}($value);
        }
    }

    // --- Generic-обработчики авто-операций (#[Get]/#[Post]/...) ---

    /**
     * Список сущностей по фильтру/сортировке/пагинации из запроса.
     */
    public function opList(Request $request): JsonResponse
    {
        $filter = $this->getParameterFilter($request);
        $sort   = $this->getParameterSort($request);

        if ($this->route?->isPaginated()) {
            return $this->paginatedList($request, $filter, $sort);
        }

        $collection = $this->repo->findAll(
            $filter,
            $sort,
            $request->query->get('limit'),
            $request->query->get('offset')
        );

        return $this->prepareItems($collection, $this->getParameterFields($request));
    }

    /**
     * Список с метаданными пагинации ({items, pagination}) — для GetCollection(paginated: true).
     *
     * @param array<string, mixed> $filter
     * @param array<string, string> $sort
     */
    protected function paginatedList(Request $request, array $filter, array $sort): JsonResponse
    {
        $limitRaw = $request->query->get('limit');
        $limit    = is_numeric($limitRaw) && (int) $limitRaw > 0 ? (int) $limitRaw : null;
        $page     = max(1, (int) $request->query->get('page'));
        $offset   = $limit ? ($page - 1) * $limit : 0;

        $collection = $this->repo->findAll($filter, $sort, $limit, $offset, true);
        $itemDto    = $this->route?->getOutput();

        $response = $itemDto
            ? PaginatedResponse::fromCollection($collection, $itemDto, $page, $limit)
            : new PaginatedResponse($collection->toArray(), [
                'page'  => $page,
                'limit' => $limit,
                'total' => $collection->getTotal(),
                'pages' => $limit ? (int) ceil($collection->getTotal() / $limit) : 1,
            ]);

        return new JsonResponse($response->toArray());
    }

    /**
     * Одна сущность по идентификатору.
     */
    public function opShow(Request $request, int $id): JsonResponse
    {
        return $this->prepareItem($this->repo->find($id), $this->getParameterFields($request));
    }

    /**
     * Создание сущности из input-DTO (или тела запроса) с хуками.
     *
     * Запись и хуки выполняются атомарно (одна транзакция): при сбое на любом
     * шаге всё откатывается.
     */
    public function opCreate(Request $request): JsonResponse
    {
        $entity = new $this->entityClass();
        $this->fillEntity($entity, $this->inputFields($request));

        DbRegistry::get()->transactional(function () use ($entity, $request) {
            $this->callHook($this->route?->getHookBefore(), $entity, $request);
            if (!$this->repo->create($entity)) {
                throw new \RuntimeException('Не удалось создать сущность.');
            }
            $entity->preloadRelations();
            $this->callHook($this->route?->getHookAfter(), $entity, $request);
        });

        return $this->prepareItem($entity);
    }

    /**
     * Частичное обновление сущности с хуками (атомарно).
     */
    public function opUpdate(Request $request, int $id): JsonResponse
    {
        $entity = $this->repo->find($id);
        $old    = clone $entity;
        $this->fillEntity($entity, $this->inputFields($request));

        DbRegistry::get()->transactional(function () use ($entity, $request, $old) {
            $this->callHook($this->route?->getHookBefore(), $entity, $request, $old);
            if (!$this->repo->update($entity)) {
                throw new \RuntimeException('Не удалось обновить сущность.');
            }
            $this->callHook($this->route?->getHookAfter(), $entity, $request, $old);
        });

        $entity->preloadRelations();

        return $this->prepareItem($entity);
    }

    /**
     * Удаление сущности с хуками (атомарно).
     */
    public function opDelete(Request $request, int $id): JsonResponse
    {
        $entity = $this->repo->find($id);

        DbRegistry::get()->transactional(function () use ($entity, $request) {
            $this->callHook($this->route?->getHookBefore(), $entity, $request);
            if (!$this->repo->delete($entity)) {
                throw new \RuntimeException('Не удалось удалить сущность.');
            }
            $this->callHook($this->route?->getHookAfter(), $entity, $request);
        });

        return new JsonResponse(['success' => true]);
    }

    /**
     * Поля для записи: из валидированного input-DTO (только заданные свойства)
     * либо, если DTO нет, из сырого JSON-тела.
     *
     * @return array<string, mixed>
     */
    protected function inputFields(Request $request): array
    {
        if ($this->input === null) {
            return $this->parseBody($request);
        }

        $fields = [];
        foreach ((new \ReflectionObject($this->input))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($this->input)) {
                $fields[$property->getName()] = $property->getValue($this->input);
            }
        }

        return $fields;
    }

    /**
     * Вызывает метод-хук операции, если он задан и определён в контроллере.
     */
    protected function callHook(?string $name, Entity $entity, Request $request, ?Entity $old = null): void
    {
        if ($name && method_exists($this, $name)) {
            $this->{$name}($entity, $request, $old);
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
