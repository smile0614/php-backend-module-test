# Code Review — модуль `Shared\Deletion`

> Ревью фрагмента внутренней библиотеки: `DeletionService` (анализ зависимостей) +
> `DeletionOrchestrator` (исполнение каскадного удаления) + атрибуты/DTO/middleware.
>
> Формат: сначала краткое резюме с приоритетами, затем детальный разбор по темам
> с ссылками на код (`файл:строка`). Часть находок подтверждена тестами из Части 2
> (`tests/DeletionServiceTest.php`) — они помечены ✔ verified.

---

## 0. TL;DR — приоритеты

| # | Severity | Где | Проблема |
|---|----------|-----|----------|
| B1 | 🔴 critical | `DeletionService::getScalarFkValue()` | Для `field`, указывающего на association-объект (`private OrderEntity $order` — основной пример из README), метод падает с `TypeError`: возвращаемый тип `int\|string\|null`, а рефлексия отдаёт объект. `canDelete()` рушится на главном задокументированном сценарии. ✔ verified |
| B2 | 🔴 critical | `DeletionOrchestrator::execute()` | Оркестратор **не проверяет `canDelete`** перед удалением. «Блокировка» — чисто информативная; `execute()` каскадно удаляет даже когда `analyze()` вернул `canDelete = false`. |
| B3 | 🔴 critical | `DeletionService::findChildrenByAttributes()` + orchestrator | Дети `BLOCKING + cascade=NONE` кладутся в `childrenDelete`, а оркестратор эти списки **физически удаляет** — то есть `NONE` («не удалять ребёнка») по факту приводит к удалению ребёнка. |
| B4 | 🔴 critical | `DeletionOrchestrator::buildRecursive()` | Рекурсия без защиты от циклов и без «visited»-множества. Циклический граф → бесконечная рекурсия/`stack overflow`; «ромб» → экспоненциальный повторный обход. |
| B5 | 🟠 high | `DeletionService::getJoinTableParentIds()` | Читает join-таблицу через **ORM** `QueryBuilder::from($joinTable)`, куда нужно передавать entity-класс, а не имя таблицы. Путь M2M-родителя, судя по всему, нерабочий (при этом в оркестраторе аналогичный DELETE сделан правильно через DBAL). |
| S1 | 🟠 high | семантика | Смысл `BLOCKING` в коде **инвертирован относительно README** и вдобавок в самом коде описан двумя противоречивыми способами. |
| P1 | 🟠 high | `findChildrenByAttributes()` | Чтобы просто ответить «можно ли удалить», модуль **гидратирует все дочерние сущности** (по всем связям, рекурсивно). Для проверки достаточно `COUNT`/`EXISTS`. N+1 и лишняя память. |
| B6 | 🟡 medium | `analyze()` | Ветка «блокируем по жёстким родителям» — **мёртвый код**: родительские связи всегда `hard=false`. |
| B7 | 🟡 medium | `DeletionOrchestrator::notify()` | Все исключения middleware **молча проглатываются** (`catch (Throwable) {}`), а `supports()` не вызывается вовсе. |
| B8 | 🟡 medium | `findChildrenByAttributes()` | `REFERENCE + NONE`-дети молча теряются (не попадают ни в один список), хотя как «зависимости» их логично показать. ✔ verified |
| Q1 | 🟡 medium | весь модуль | ORM 2.x-специфичный код (`fieldMappings[...]['type']`, `'json_array'`) не переживёт миграцию на ORM 3.x. |
| — | 🔵 low | разное | PSR-каталогизация, docblock-и, magic strings, именование, composite keys, батчинг `IN (...)`. |

Ниже — по темам.

---

## 1. Что хорошо

Чтобы ревью не выглядело односторонним — сильные стороны действительно есть:

- **Декларативная модель через атрибуты.** `#[RelationTo]` на дочерней сущности —
  читаемый способ описать граф зависимостей рядом с самим кодом сущности.
  `Attribute::IS_REPEATABLE` для множественных зависимостей — правильно
  (`Attribute/RelationTo.php:10`).
- **Разделение «анализ» и «исполнение».** `DeletionService` (что удалять) отделён
  от `DeletionOrchestrator` (как удалять, транзакция, хуки). Это хорошая ось для
  дальнейшего развития и тестируемости.
- **Middleware-хуки** вокруг фаз (`before/after` detach/deleteChildren/deleteRoot)
  и `iterable`-инъекция middlewares — расширяемо, ложится на Symfony DI
  `tagged_iterator`.
- **Immutable DTO** (`readonly`), явные `enum`-ы (`RelationType`, `DeletionCascade`)
  вместо «магических» булевых/строк на границе API — правильное направление.
- **`declare(strict_types=1)`** во всех файлах, типизированные свойства, `final` —
  базовая гигиена соблюдена.
- **`dryRun`** в `execute()` — полезно для предпросмотра плана.
- Кэширование карты (`$map`) и метаданных (`$metadataCache`) — разумно (с оговорками
  ниже про инвалидцию и стоимость первого прогрева).

---

## 2. Критичные баги

### B1. `getScalarFkValue()` падает с `TypeError` на association-объекте 🔴 ✔ verified

`DeletionService.php:227-239`

```php
private function getScalarFkValue(object $object, string $field): int|string|null
{
    // ...
    return $property->getValue($object); // вернёт ОБЪЕКТ для private OrderEntity $order
}
```

README, пример №1 — основной сценарий — описывает `field: 'order'` при
`private OrderEntity $order`. Рефлексия вернёт объект `OrderEntity`, а сигнатура
метода — `int|string|null`. Итог: жёсткий `TypeError` прямо на `return`, и весь
`analyze()/canDelete()` падает.

Тест `association_object_field_throws_a_type_error()` это фиксирует.

**Причина:** модуль путает два разных случая — *скалярный FK-столбец* (`orderId`)
и *ассоциацию-объект* (`order`). Для ассоциации нужно доставать идентификатор через
метаданные: `EntityManager::getClassMetadata($fqcn)->getIdentifierValues($related)`
(или `getSingleIdReflicionValue`). Предлагаемое исправление:

```php
private function resolveParentId(object $object, string $field): int|string|null
{
    $value = $this->readProperty($object, $field); // рефлексия
    if ($value === null) {
        return null;
    }
    if (is_object($value)) {
        // ассоциация: берём идентификатор связанной сущности
        $ids = $this->em->getClassMetadata($value::class)->getIdentifierValues($value);
        return $ids ? (array_values($ids)[0]) : null;
    }
    return is_int($value) || is_string($value) ? $value : null;
}
```

### B2. `execute()` не проверяет `canDelete` 🔴

`Service/DeletionOrchestrator.php:28-60`

`execute()` вызывает `plan()` (= `analyze()`), строит план и сразу удаляет в
транзакции. Нигде нет проверки `$relations->canDelete`. Значит, вся «блокирующая»
семантика на этапе исполнения **игнорируется**: сущность с жёсткими зависимостями
всё равно будет удалена. Либо `execute()` должен бросать исключение при
`!canDelete` (кроме явного `force`-режима), либо контракт нужно проговорить в
имени/доке. Сейчас это тихая потеря инварианта.

### B3. `cascade=NONE` у блокирующего ребёнка приводит к его удалению 🔴

`DeletionService.php:283-293`

```php
} elseif ($hard) {
    // Для BLOCKING связей с cascade=NONE всё равно добавляем в childrenDelete,
    $childrenDelete[] = new DependentGroupDto(...);
}
```

Класть блокирующих детей в `childrenDelete` только ради подсчёта в `canDelete` —
опасно, потому что **этот же список** `childrenDelete` оркестратор передаёт в
`deleteByIds()` (`DeletionOrchestrator.php:44-50, 111-121`). Итог: `NONE`
(«не удалять ребёнка вместе с родителем») по факту удаляет ребёнка, если дошло до
`execute()`. Списки «для решения о блокировке» и «для физического удаления» нельзя
смешивать в одном поле — их надо разнести (см. §7 «редизайн»).

### B4. Рекурсия без защиты от циклов и дублей 🔴

`Service/DeletionOrchestrator.php:89-141`

`buildRecursive()` для каждого ребёнка каждого id рекурсивно спускается вглубь и
заново вызывает `analyze()`, **не запоминая уже посещённые узлы**. Последствия:

- **Цикл** (`A → B → A`, или самоссылка) → бесконечная рекурсия и падение.
- **Ромб** (`A→B, A→C, B→D, C→D`) → `D` анализируется многократно.
- Для каждого id — отдельный `find()`/`findOneBy()` (`:124-132`) → классический N+1.

Нужен `visited: array<class-string, set<id>>`, обход графа (BFS/итеративно) и
пакетная загрузка детей одним запросом на класс, а не по одному id.

### B5. Чтение join-таблицы через ORM QueryBuilder 🟠

`DeletionService.php:211-225`

```php
$qb = $this->em->createQueryBuilder();
$qb->select("jt.{$joinColumn}")->from($joinTable, 'jt')  // <-- $joinTable это имя ТАБЛИЦЫ
```

`QueryBuilder::from()` в ORM ждёт **entity-класс**, а не имя таблицы. Для сырой
промежуточной таблицы (`advert_tag_relation`) это не сработает — DQL попытается
найти сущность с таким именем. Показательно, что в оркестраторе тот же join
удаляется **правильно** — через DBAL и сырой SQL (`DeletionOrchestrator::detachJoinRow()`,
`:168-174`). Здесь нужно так же: `$this->em->getConnection()->createQueryBuilder()`
(DBAL) или прямой SQL с параметрами. Сейчас путь «M2M-родитель» в
`findParentsByAttributes()` фактически нерабочий.

---

## 3. Семантика и соответствие README

### S1. Инвертированный и противоречивый смысл `BLOCKING` 🟠

Три источника правды расходятся:

1. **README, пример №1 и №4:** «`OrderItemEntity` **нельзя удалить**, если она
   связана с `OrderEntity`»; «при проверке `AdvertTag` … тег **нельзя удалить**».
   То есть `BLOCKING` должен блокировать **ту сущность, на которой висит атрибут**
   (ребёнка).
2. **Комментарий в коде** (`DeletionService.php:120-122`): «Родительские связи
   НИКОГДА не должны блокировать удаление текущей сущности; `BLOCKING` означает
   "нельзя удалить РОДИТЕЛЯ"». Это **противоположность** README.
3. **Фактическое поведение кода:** `findParentsByAttributes()` жёстко ставит
   `$isBlocking = false` (`:122`), значит блокирует только `hasHardChildren`
   (`analyze()`, `:57-62`) — т.е. блокируется **родитель**, к которому пришли дети.

Тест `a_blocking_parent_relation_does_not_block_the_child_itself()` фиксирует
пункт 3: `OrderItem` с `BLOCKING`-связью и заполненным FK **удаляется свободно**,
что прямо противоречит README. Нужно определиться с единственной семантикой и
привести к ней и код, и README, и примеры. Плюс README описывает вообще другой
атрибут — `DependsOn(parent:, hard:)` — тогда как в коде `RelationTo(entity:, type:,
cascade:)`. Документация устарела/рассинхронизирована.

### B6. Мёртвая ветка «блокируем по жёстким родителям» 🟡

`DeletionService.php:46-52` — цикл по `$parents` с проверкой `$group->hard`
недостижим по-настоящему: `findParentsByAttributes()` всегда создаёт группы с
`hard=false`. `$hasHardParent` не может стать `true`. Либо это остаток прежней
семантики (тогда удалить), либо баг (тогда `isBlocking` должен вычисляться).

---

## 4. Edge cases (необработанные)

- **B8. `REFERENCE + NONE`-дети теряются** (`:283-293`) — не попадают ни в
  `childrenDelete`, ни в `childrenDetach`, ни в отчёт `dependents`. Пользователь
  не увидит эти связи вовсе. ✔ verified (`reference_child_without_cascade_is_not_reported_as_dependent`).
- **`0` как валидный id.** `getScalarFkValue`-проверка `$parentId !== 0 && !== ''`
  (`:150`) трактует `0`/`''` как «нет ссылки». Для БД с нулевыми/строковыми ключами
  это ложноотрицательные срабатывания. ✔ verified (`scalar_fk_that_is_zero_is_treated_as_absent`).
- **`array_filter` ломает `list<>`** (`getJsonArrayParentIds`, `:198`): при дырках
  в JSON-массиве вернётся не-список с сохранёнными ключами, а DTO объявлен как
  `list<int|string>`. Нужен `array_values(array_filter(...))`.
- **JSON-фильтр слишком широкий:** `is_numeric($id) || is_string($id)` (`:198`)
  пропускает любые строки (включая мусор) как id.
- **Composite keys не поддержаны:** `array_values($parentIdArr)[0] ?? null`
  (`DeletionOrchestrator.php:92-93`) молча берёт только первый столбец
  идентификатора. Для составных ключей — тихо неверный результат.
- **`deleteByIds` смешивает семантику `field`:** удаляет `WHERE e.{field} IN (:ids)`,
  где `field='id'` для обычных сущностей, но `$group->field` (FK!) для сущностей без
  `id` (`DeletionOrchestrator.php:112-121, 180-188`). Во втором случае в `:ids`
  лежат id детей, а фильтр идёт по FK-полю — потенциально неверная выборка.
- **Огромные `IN (...)`:** ни `deleteByIds`, ни `detachJoinRow` не батчат id.
  Тысячи детей → превышение лимита плейсхолдеров/размера запроса. Нужен `array_chunk`.
- **Пустой `$this->map` до `ensureMap()`:** `findChildrenByAttributes()` читает
  `$this->map[$parentClass]` (`:254`), полагаясь на то, что `analyze()` уже вызвал
  `ensureMap()`. Хрупкая неявная зависимость по порядку вызовов.

---

## 5. Производительность

- **P1. `canDelete` гидратирует сущности вместо `COUNT`.**
  `findChildrenByAttributes()` (`:248-297`) для каждого правила тянет **полные
  дочерние объекты** (`findByAssociation`/`findByJoinTable`/`findByJsonContains`) и
  затем в цикле собирает их id через `getId()`. Чтобы ответить «можно ли удалить»,
  достаточно `COUNT(...) > 0` или `EXISTS`. Сейчас — загрузка потенциально десятков
  тысяч объектов в память ради подсчёта. Это главный перф-провал: тяжело и по
  памяти, и по времени, и провоцирует N+1 в UoW.
- **N+1 в оркестраторе.** `buildRecursive()` грузит каждого ребёнка отдельным
  `find()`/`findOneBy()` в цикле (`:124-132`). Нужна пакетная загрузка.
- **`ensureMap()` прогревает всё.** `getMetadataFactory()->getAllMetadata()`
  (`:82`) грузит метаданные **всех** сущностей и делает `new ReflectionClass` на
  каждую. На больших схемах первый вызов дорогой. Кандидат на компиляцию карты в
  сборке/кэш (PSR-6/Symfony cache warmer), а не рантайм-скан.
- **Кэши не инвалидируются** и живут в свойствах инстанса — в долгоживущих воркерах
  (RoadRunner/Swoole) при изменении метаданных не обновятся (маловероятно, но стоит
  осознавать).
- **Загрузка ради detach.** Для detach нужен только `id`, а грузятся полные
  сущности.

---

## 6. Middleware / оркестрация

- **B7. Молчаливое проглатывание исключений.** `notify()` (`DeletionOrchestrator.php:156-166`)
  оборачивает вызов в `try { … } catch (Throwable) {}`. Ошибка в middleware
  (например, в метриках или в аудите, который *обязан* отработать) исчезает
  бесследно. Как минимум — логировать проглоченное; лучше — не глотать вовсе.
- **`supports()` не вызывается.** Интерфейс объявляет `supports(string): bool`
  (`Middleware/DeletionMiddlewareInterface.php:9`), но `notify()` его не проверяет —
  фильтрация по классу мертва, каждый middleware получает все события.
- **`method_exists()` вместо контракта.** `notify()` дергает
  `method_exists($mw, $method)` (`:159`), хотя интерфейс уже гарантирует наличие
  всех методов. Если задумано «частичное» middleware — нужен либо базовый
  no-op-класс/трейт, либо разбиение интерфейса (ISP).
- **`after*` вызываются внутри транзакции, до commit.** `afterDeleteRoot` — это не
  «после удаления», а «перед commit» (`:33-59`). Любой middleware, считающий успех
  (метрики, вебхуки, инвалидция кэша), зафиксирует его даже если commit потом
  упадёт. Для честного success-rate нужен post-commit хук, которого в интерфейсе
  нет. (Это ограничение я явно задокументировал в `MetricsDeletionMiddleware`.)
- **`dryRun` не прокидывается в middleware** — dry-run и реальное удаление
  неотличимы на уровне хуков. Стоит завести объект-контекст операции
  (`OperationContext { root, dryRun, correlationId }`) и передавать его в колбэки —
  заодно решает проблему «operationStart/Commit».

---

## 7. SOLID / PSR / Symfony best practices

**SRP.** `DeletionService` совмещает слишком многое: построение карты из атрибутов,
рефлексию, декодирование JSON, инспекцию метаданных Doctrine, построение запросов,
резолв id. Напрашивается разбор на: `RelationMapBuilder` (сканирование атрибутов →
карта, кэшируемо), `ParentIdResolver`/`ChildFinder` (стратегии scalar / json /
join-table), собственно `DependencyAnalyzer`. Сейчас же ещё и имя вводит в
заблуждение: «`DeletionService`» ничего не удаляет — это анализатор (в оркестраторе
он и внедрён как `$analyzer`).

**OCP / Strategy.** Три способа резолва связи (scalar / json / join-table)
размазаны `if/elseif` и продублированы в родительской и дочерней ветках. Это просится
в набор стратегий `RelationResolverInterface`, выбираемых по типу связи — новый тип
связи не должен требовать правок в двух больших методах.

**ISP.** `DeletionMiddlewareInterface` — «толстый» (7 методов). Учитывая, что
оркестратор всё равно проверяет `method_exists`, стоит либо разбить на мелкие
интерфейсы (`DetachAware`, `DeleteChildrenAware`, …), либо дать
`AbstractDeletionMiddleware` с no-op-реализациями, чтобы клиенты переопределяли
только нужное.

**DIP.** `DeletionService` зависит от **конкретного** `GenericReadRepository`
(`:23`), а не от интерфейса — юнит-тесты вынуждены мокать конкретный класс, а
подмена реализации невозможна. Нужен `ReadRepositoryInterface`. То же касается
жёсткой привязки к деталям `ClassMetadata` (см. Q1).

**Разделение «модель решения» и «модель исполнения».** `DependentGroupDto`
используется и для отчёта в `canDelete`, и как источник id для физического удаления —
из-за этого возник B3. Правильно: `analyze()` возвращает *отчёт о зависимостях*
(для UI/решения), а планировщик отдельно строит *исполняемый план* (что реально
удалять/детачить), с явными и раздельными списками.

**PSR-4 / структура каталогов.** Пространство имён — `Shared\Deletion`, но файлы
лежат в каталоге `Deletion/` без сегмента `Shared/` — маппинг PSR-4 придётся
подкручивать (`"Shared\\Deletion\\": "Deletion/"`). Мелочь, но на автозагрузке
всплывёт. `DeletionService` лежит в корне модуля, а родственный `DeletionOrchestrator`
— в `Service/`; логичнее держать их рядом.

**PSR-3.** `LoggingDeletionMiddleware` использует `compact()` с потенциально большими
массивами `childIds` в контексте — на больших удалениях это раздует логи; стоит
логировать `count($childIds)` и, например, первые N id.

**PSR-12 / доки.** Docblock-и местами неверны: у `DependentGroupDto` порядок
`@param` не совпадает с конструктором; у `OrderedPlanDto` строка `@param $delete`
«съела» описание `$detach`; в сигнатуре карты `getChildRelationRules()` индекс 6
описан как `?string`, хотя туда кладётся строковое значение `cascade`. `$metadataCache`
без типа свойства (`array` без generic-аннотации).

**Symfony.** Инъекция `iterable $middlewares` идеально ложится на
`#[TaggedIterator]`/`!tagged_iterator`, но стоит зафиксировать это в конфиге/атрибутом
и определить порядок middleware (`priority`). Консольная команда `deletion:check` из
README в архиве отсутствует — если она часть модуля, её тоже стоит покрыть.

**Безопасность запросов.** Идентификаторы таблиц/колонок (`joinTable`, `joinColumn`)
подставляются в SQL/DQL интерполяцией строк (`detachJoinRow` `:172`,
`getJoinTableParentIds` `:216`). Значения параметризованы (хорошо), но сами
идентификаторы — нет. Источник — атрибуты (доверенные, не пользовательский ввод),
поэтому это не инъекция здесь и сейчас, но стоит хотя бы валидировать имена по
белому списку/regex, чтобы случайная опечатка не превратилась в проблему.

### Q1. Привязка к ORM 2.x

`isJsonField()` (`:313-326`) читает `$metadata->fieldMappings[$field]['type']` как
**массив** и сравнивает с `'json_array'`. В Doctrine ORM 3.x `fieldMappings`
содержит объекты `FieldMapping`, а тип `json_array` давно deprecated. На ORM 3.x код
сломается. Для переносимости — работать через API метаданных
(`$metadata->getTypeOfField($field)`), а не через приватную структуру, и убрать
`json_array`.

---

## 8. Что бы я сделал иначе (кратко, редизайн)

1. **Разнести «отчёт» и «план».** `analyze()` → `DependencyReport` (для решения и
   UI). Отдельный `DeletionPlanner` → `ExecutionPlan` с **раздельными** списками
   `toDelete` / `toDetach`, где `BLOCKING+NONE` **никогда** не попадает в `toDelete`
   (закрывает B3).
2. **`execute()` уважает решение.** По умолчанию бросать
   `DeletionBlockedException`, если `!report->canDelete`; отдельный явный
   `force`-режим — для осознанного обхода.
3. **Стратегии резолва связи** (`Scalar` / `Json` / `JoinTable`), выбираемые по
   типу — устраняет дублирование parent/child и `if/elseif` (закрывает часть B1/B5).
4. **Считать, а не грузить.** Для `canDelete` — `COUNT/EXISTS`; сущности грузить
   только когда реально нужно удалять (закрывает P1).
5. **Итеративный обход графа с `visited` и пакетной загрузкой** вместо рекурсии по
   одному id (закрывает B4 и N+1).
6. **Единая семантика `BLOCKING`,** синхронно отражённая в коде, README и примерах
   (закрывает S1/B6). README привести к реальному атрибуту `RelationTo`.
7. **`ReadRepositoryInterface`** вместо конкретного репозитория; работа с
   метаданными через публичный API (закрывает DIP и Q1).
8. **`OperationContext` в middleware** (`dryRun`, `correlationId`) + post-commit
   хук + не проглатывать исключения без логирования (закрывает B7 и ограничения §6).
9. **Батчинг `IN (...)`** через `array_chunk` и поддержка составных ключей.

---

## 9. Мелочи (low)

- Имя `DeletionService` не отражает роли (это анализатор). Переименовать в
  `DependencyAnalyzer`.
- `getScalarFkValue` объявлен `int|string|null`, но `// @var` внутри — лишний.
- `metadataCache`/`map` — добавить типизацию/generic-доки.
- Порядок кортежа карты (7 элементов, позиционный доступ) хрупок — заменить на DTO
  `RelationRule` вместо `array{0:..,1:..}`; позиционная деструктуризация в
  `buildRecursive()` и `findChildrenByAttributes()` легко ломается при добавлении
  поля.
- Magic strings: в `buildRecursive()` сравнение `$cascade === 'detach'`
  (`:95`) — использовать `DeletionCascade::DETACH_RELATIONS->value`.
- `OrderedPlanDto` — `final`, но поля `public readonly` без интерфейса; для
  единообразия с другими DTO сделать `final readonly class`.

---

## 10. Связь с Частью 2

- **Вариант A — юнит-тесты `DeletionService`:** `tests/DeletionServiceTest.php`.
  22 теста зелёные; часть из них намеренно фиксирует текущее (спорное) поведение
  (B1, B8, S1, «0 как отсутствие»), чтобы будущий рефакторинг был заметен по
  диффу тестов.
- **Вариант B — `MetricsDeletionMiddleware`:**
  `src/Deletion/Middleware/MetricsDeletionMiddleware.php` + абстракция
  `Metrics/MetricsRecorderInterface.php` и тест
  `tests/MetricsDeletionMiddlewareTest.php`. Что и почему измеряю — в шапке класса
  и в `README.md`.

Как запускать — см. `README.md`.
