# Тестовое задание — модуль Deletion

Ответ на задание: **Часть 1 (code review)** и **Часть 2 — все варианты (A + B)**.

## Что где лежит

```
├── CODE_REVIEW.md                     # ЧАСТЬ 1 — детальное ревью (приоритизировано)
│
├── src/Deletion/                      # исходный модуль (as-is) + добавленное для Части 2
│   ├── DeletionService.php            #   — ревьюится
│   ├── Service/DeletionOrchestrator.php
│   ├── Attribute/ Dto/ Enum/
│   ├── Middleware/
│   │   ├── DeletionMiddlewareInterface.php
│   │   ├── LoggingDeletionMiddleware.php
│   │   └── MetricsDeletionMiddleware.php   # ЧАСТЬ 2B — новый middleware
│   └── Metrics/                             # ЧАСТЬ 2B — абстракция транспорта метрик
│       ├── MetricsRecorderInterface.php
│       └── InMemoryMetricsRecorder.php
│
├── tests/
│   ├── DeletionServiceTest.php              # ЧАСТЬ 2A — юнит-тесты DeletionService
│   ├── MetricsDeletionMiddlewareTest.php    # ЧАСТЬ 2B — тесты middleware
│   ├── Fixture/                             #   аннотированные сущности-фикстуры
│   ├── Support/                             #   управляемые часы, фабрика метаданных
│   └── bootstrap.php                        #   автозагрузка + тест-дублёры внешних типов
│
├── composer.json
└── phpunit.xml
```

## Часть 2A — юнит-тесты `DeletionService`

22 теста (`tests/DeletionServiceTest.php` + `MetricsDeletionMiddlewareTest.php`),
покрывают:

- `canDelete`: happy-path, блокировка жёсткими детьми, агрегирование зависимостей;
- правило «detach не блокирует родителя даже при `BLOCKING`»;
- JSON-родитель (массив / JSON-строка / битый JSON / пустой);
- JSON-ребёнок (`findByJsonContains`) и ветка `BLOCKING + cascade=NONE`;
- скалярный FK: `null` / `0` как «нет ссылки»;
- **регресс-фиксация багов** из ревью: `TypeError` на association-объекте (B1),
  «родитель не блокирует ребёнка» (S1), потеря `REFERENCE+NONE`-детей (B8).

Тесты, фиксирующие текущее (спорное) поведение, помечены в коде
`documents current behavior` и разобраны в `CODE_REVIEW.md`.

## Часть 2B — `MetricsDeletionMiddleware`

`src/Deletion/Middleware/MetricsDeletionMiddleware.php`.

**Что измеряю и почему** (подробно — в шапке класса):

| Метрика | Тип | Зачем |
|---|---|---|
| `deletion.operation.duration_ms` | timing | сколько длится вся операция удаления |
| `deletion.{detach,children,root}.duration_ms` | timing | где именно тратится время (по фазам) |
| `deletion.detach.relations` | counter | сколько связей разорвано |
| `deletion.children.deleted` | counter | сколько дочерних записей удалено |
| `deletion.children.batch_size` | distribution | распределение размеров батчей (ловим p95/p99 «тяжёлых» удалений) |
| `deletion.root.deleted` / `operation.completed` | counter | throughput |

Класс сущности — в тегах, не в имени метрики (кардинальность). Транспорт скрыт за
`MetricsRecorderInterface`, чтобы не привязываться к StatsD/Prometheus/OTel; часы —
`Psr\Clock\ClockInterface` (тестируемость). В шапке класса явно перечислены
ограничения, вытекающие из текущего контракта оркестратора (нет
`operationStart`/post-commit хука, `after*` внутри транзакции, оркестратор глушит
исключения middleware, `dryRun` не прокидывается) — они же отмечены в ревью.

## Как запустить тесты

Нужен PHP ≥ 8.2. Тесты **не требуют** реального Doctrine или БД: внешние
коллабораторы подменены лёгкими тест-дублёрами в `tests/bootstrap.php` (все
определения защищены `*_exists()`, поэтому в реальном проекте с настоящим Doctrine
заглушки не активируются).

```bash
composer install
composer test
# или напрямую:
vendor/bin/phpunit
```

Прогон в песочнице (PHPUnit 11.5, PHP 8.2.12):

```
OK (22 tests, 71 assertions)
```

> Примечание про окружение: у меня не было composer/Doctrine под рукой, поэтому
> `bootstrap.php` определяет минимальные дублёры `EntityManagerInterface`,
> `ClassMetadata`, `ClassMetadataFactory`, `GenericReadRepository`, `ClockInterface`.
> Тела тестов (Arrange/Act/Assert) — ровно те же, что писались бы против реальных
> типов; карта зависимостей строится из **настоящих** атрибутов `#[RelationTo]` на
> фикстурах через рефлексию.
