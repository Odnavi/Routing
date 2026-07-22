# odnavi/routing

Атрибутный роутинг для PHP 8.2+: маршруты объявляются атрибутом `#[Route]` на
контроллерах, `ClassScanner` находит контроллеры в зарегистрированных
неймспейсах, `RouteFactory` собирает и кэширует карту маршрутов. Поверх базового
роутинга — слой CRUD: авто-операции атрибутами и типизированные input/output
DTO с валидацией.

## Содержание

- [Установка и подключение](#установка-и-подключение)
- [Базовый роутинг](#базовый-роутинг)
- [Контроллеры](#контроллеры)
- [CRUD: два уровня](#crud-два-уровня)
  - [1. Ручные ручки](#1-ручные-ручки)
  - [2. Авто-операции](#2-авто-операции)
- [Input/Output DTO](#inputoutput-dto)
- [preHandle](#prehandle)
- [Поток запроса и кэш](#поток-запроса-и-кэш)

## Установка и подключение

Каждый пакет-потребитель регистрирует свой неймспейс контроллеров (обычно в
`bootstrap.php`):

```php
use Odnavi\Routing\Service\RouteFactory;

RouteFactory::registerNamespace('Money\Controller');
```

`ClassScanner` сканирует классы с суффиксом `Controller` в этих неймспейсах.
Карта маршрутов строится по class-map автозагрузчика, поэтому после изменения
роутов нужен оптимизированный автолоадер:

```bash
composer dump-autoload -o
```

## Базовый роутинг

Атрибут `#[Route]` ставится на **класс** (задаёт группу-префикс) и на **методы**
(конкретные маршруты). Атрибут повторяемый.

```php
use Odnavi\Routing\Attribute\Route;
use Odnavi\Routing\Controller\AbstractController;
use Odnavi\Routing\Request;
use Odnavi\Routing\JsonResponse;

#[Route('/api/v1/things')]
class ThingController extends AbstractController
{
    #[Route('', methods: 'GET')]
    public function list(Request $request): JsonResponse
    {
        return new JsonResponse([]);
    }

    #[Route('/{id}', methods: 'GET', requirements: ['id' => '\d+'])]
    public function read(Request $request, int $id): JsonResponse
    {
        return new JsonResponse(['id' => $id]);
    }
}
```

Параметры конструктора `#[Route]`:

| Параметр | Тип | Назначение |
|----------|-----|-----------|
| `path` | `?string` | путь; на классе — префикс-группа |
| `methods` | `string\|string[]` | HTTP-методы (по умолчанию `GET`) |
| `requirements` | `array` | regex для path-параметров, напр. `['id' => '\d+']` |
| `preHandle` | `?string` | гвард до обработчика: имя метода контроллера или callable-строка (`Class::method`). На класс-level — дефолт для всех ручек контроллера |
| `input` | `?string` | DTO входа: типизация + валидация |
| `output` | `?string` | DTO выхода: форма ответа |

Path-параметры (`/{id}`) прилетают в обработчик по имени. Для сложных сигнатур
используй именованные аргументы атрибута:

```php
#[Route('/{id}/items/{itemId}', methods: 'GET', requirements: ['id' => '\d+', 'itemId' => '\d+'])]
public function item(Request $request, int $id, int $itemId): JsonResponse { /* ... */ }
```

## Контроллеры

Контроллеры наследуют `Odnavi\Routing\Controller\AbstractController`, который даёт:

- `$this->repo` — репозиторий сущности (если задан `$entityClass`);
- `currentUserId()` / `requireUserId()` / `assertOwner()` — работа с текущим пользователем;
- `prepareItem()` / `prepareItems()` — сериализация сущности/коллекции в `JsonResponse` (с учётом output-DTO);
- `parseBody()` / `fillEntity()` — тело запроса и заполнение сущности;
- `getParameterFields/Filter/Sort()` — разбор `?fields=`, `?filter=`, `?sort=`.

## CRUD: два уровня

Два способа получить CRUD-ручки — от полного контроля до нуля кода. Оба
сосуществуют.

### 1. Ручные ручки

Пишешь методы сам, размечаешь `#[Route]`. Максимальный контроль (см. пример
выше).

### 2. Авто-операции

Атрибуты-операции на классе контроллера. Каждый несёт целевую сущность —
ручка синтезируется автоматически, обработчик и логику даёт `AbstractController`.

| Атрибут | Маршрут | Хуки |
|---------|---------|------|
| `#[GetCollection]` | `GET /` | — |
| `#[Get]` | `GET /{id}` | — |
| `#[Post]` | `POST /` | `beforeCreate`/`afterCreate` |
| `#[Patch]` | `PATCH /{id}` | `beforeUpdate`/`afterUpdate` |
| `#[Delete]` | `DELETE /{id}` | `beforeDelete`/`afterDelete` |

Параметры каждой операции: `entity` (обяз.), `input`, `output`, `preHandle` (гвард),
`beforeHook`/`afterHook` (переопределение имён хуков), `paginated` (для `GetCollection` —
ответ в форме `{items, pagination}`).

```php
use Odnavi\Routing\Attribute\{Route, GetCollection, Get, Post, Patch, Delete};
use Odnavi\Routing\Controller\AbstractController;

#[Route('/api/v1/things')]                                              // префикс группы
#[GetCollection(entity: ThingEntity::class, output: ThingOutput::class, preHandle: 'auth')]
#[Get(entity: ThingEntity::class, output: ThingOutput::class, preHandle: 'auth')]
#[Post(entity: ThingEntity::class, input: CreateThingInput::class, output: ThingOutput::class, preHandle: 'auth')]
#[Patch(entity: ThingEntity::class, input: UpdateThingInput::class, output: ThingOutput::class, preHandle: 'auth')]
#[Delete(entity: ThingEntity::class, preHandle: 'auth')]
class ThingController extends AbstractController
{
    public function auth(): void { $this->requireUserId(); }
}
```

Нужен неполный набор — оставь только нужные атрибуты.

### Хуки операций

Опциональные методы контроллера, вызываются вокруг работы с сущностью
(получают саму сущность). Определяются по соглашению из таблицы выше или
переопределяются через `beforeHook`/`afterHook` в атрибуте.

```php
use Odnavi\Core\Contract\Entity;

// по соглашению
protected function beforeCreate(Entity $entity, Request $request): void
{
    $entity->setUserId($this->requireUserId());
}

// override имени: #[Delete(entity: ..., afterHook: 'onDeleted')]
protected function onDeleted(Entity $entity, Request $request): void { /* ... */ }
```

Сигнатуры: create/delete — `($entity, $request)`; update — `($entity, $request, $old)`
(третий аргумент — сущность до изменения). Все хуки опциональны.

> Не путать с `preHandle`: `preHandle` — гвард в начале запроса (получает `Request`,
> до всякой логики), а хуки операции — вокруг записи в БД (получают сущность).

## Input/Output DTO

Типизированный приём с валидацией и типизированная форма ответа — указываются
прямо в `#[Route]`.

**Input-DTO** — публичные свойства с констрейнтами `#[Assert\*]`:

```php
use Symfony\Component\Validator\Constraints as Assert;

final class CreateThingInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\Positive]
    public ?int $typeId = null;
}
```

> Делай поля **nullable с дефолтом** (`?string $name = null`) — тогда отсутствие
> поля даёт нарушение валидации, а не ошибку неинициализированного свойства.

**Output-DTO** — реализует `OutputDto` (`fromEntity` + `toArray`):

```php
use Odnavi\Routing\Contract\OutputDto;

final class ThingOutput implements OutputDto
{
    public function __construct(public int $id, public string $name) {}

    public static function fromEntity(object $entity): static
    {
        /** @var ThingEntity $entity */
        return new self($entity->getId(), $entity->getName());
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
```

> Параметр `fromEntity(object $entity)` намеренно `object` — контракт не завязан
> на ORM. Сужать до `AbstractEntity` нельзя (PHP запретит по контравариантности).

**Ручной обработчик** — валидированный DTO приходит аргументом по типу:

```php
#[Route('/{id}', methods: 'PATCH', input: CreateThingInput::class, output: ThingOutput::class)]
public function update(Request $request, int $id, CreateThingInput $input): JsonResponse
{
    // сюда попадём только с валидным $input; иначе клиент получит 422
}
```

`output` работает и в авто-операциях: `prepareItem`/`prepareItems` применяют его
к сущностям, если он задан на маршруте.

При невалидном входе бросается `My\Exception\InvalidArgumentException` с перечнем
нарушений (маппится в ответ ошибки). Ключи тела сопоставляются и в `snake_case`,
и в `camelCase` (`type_id` → `typeId`).

## preHandle

Гвард, вызываемый до обработчика (например, авторизация). Три формы значения:

```php
// 1) метод контроллера
#[Route('', methods: 'GET', preHandle: 'auth')]
public function list(Request $request): JsonResponse { /* ... */ }
public function auth(Request $request): void { $this->requireUserId(); }

// 2) callable-строка — переиспользуемый гвард вне контроллера
#[Route('', methods: 'GET', preHandle: 'App\Security\AuthGuard::handle')]

// 3) на класс-level #[Route] — применяется ко всем ручкам контроллера
//    (операция/метод может переопределить своим preHandle)
#[Route('/api/v1/things', preHandle: 'auth')]
#[GetCollection(entity: ThingEntity::class, output: ThingOutput::class)]  // наследует auth
class ThingController extends AbstractController { public function auth(): void { $this->requireUserId(); } }
```

### Пагинация списка

`#[GetCollection(paginated: true)]` — `opList` отдаёт `{items, pagination}`
(через `Api\Dto\Response\PaginatedResponse`), читая `?page` и `?limit`. Без флага —
плоский массив.

## Поток запроса и кэш

1. `RouteFactory::get($request)` — ищет маршрут; карта берётся из Redis
   (`route_map`) или пересобирается сканированием.
2. `Route::handle($request)`:
   - вызывает `preHandle` (если задан) — метод контроллера или callable;
   - создаёт контроллер и прокидывает в него маршрут (`bindRoute` — нужен для
     доступа к `output`-DTO в `prepareItem`/`prepareItems`);
   - если задан `input` — валидирует и инъектит DTO аргументом;
   - вызывает обработчик, path-параметры передаются по имени.
3. Обработчик возвращает `Odnavi\Routing\JsonResponse`.

**Кэш маршрутов** живёт в Redis под ключом `route_map`. После добавления/изменения
маршрутов сбрось кэш и пересобери автолоадер:

```bash
composer dump-autoload -o
# + сброс ключа route_map в Redis
```
