<?php

declare(strict_types=1);

namespace Shared\Deletion\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Deletion\DeletionService;
use Shared\Deletion\Dto\CanDeleteDto;
use Shared\Deletion\Tests\Fixture\Category;
use Shared\Deletion\Tests\Fixture\Comment;
use Shared\Deletion\Tests\Fixture\Invoice;
use Shared\Deletion\Tests\Fixture\Order;
use Shared\Deletion\Tests\Fixture\OrderItem;
use Shared\Deletion\Tests\Fixture\Post;
use Shared\Deletion\Tests\Fixture\Tag;
use Shared\Deletion\Tests\Support\ArrayMetadataFactory;
use Shared\Persistence\GenericReadRepository;

#[CoversClass(DeletionService::class)]
final class DeletionServiceTest extends TestCase
{
    private const MAPPED_ENTITIES = [
        Order::class,
        OrderItem::class,
        Invoice::class,
        Tag::class,
        Category::class,
        Post::class,
        Comment::class,
    ];

    /** @var array<class-string, list<object>> результаты finder->findByAssociation() по классу ребёнка */
    private array $assocReturns = [];

    /** @var list<object> результат finder->findByJoinTable() */
    private array $joinTableReturns = [];

    /** @var list<object> результат finder->findByJsonContains() */
    private array $jsonContainsReturns = [];

    private GenericReadRepository $finder;

    protected function setUp(): void
    {
        $this->finder = $this->createFinder();
    }

    #[Test]
    public function it_allows_deletion_when_no_dependents_exist(): void
    {
        $service = $this->createService();

        $result = $service->canDelete(new Order(1));

        self::assertInstanceOf(CanDeleteDto::class, $result);
        self::assertTrue($result->canDelete);
        self::assertSame([], $result->dependents);
    }

    #[Test]
    public function it_blocks_deletion_when_a_blocking_child_exists(): void
    {
        $this->assocReturns[OrderItem::class] = [new OrderItem(id: 10, orderId: 1)];

        $result = $this->createService()->canDelete(new Order(1));

        self::assertFalse($result->canDelete);
        $group = $this->findDependent($result, OrderItem::class);
        self::assertNotNull($group, 'OrderItem должен присутствовать среди зависимостей');
        self::assertTrue($group->hard);
        self::assertSame(1, $group->count);
        self::assertSame([10], $group->ids);
    }

    #[Test]
    public function it_reports_count_and_ids_for_multiple_blocking_children(): void
    {
        $this->assocReturns[OrderItem::class] = [
            new OrderItem(id: 10, orderId: 1),
            new OrderItem(id: 11, orderId: 1),
            new OrderItem(id: 12, orderId: 1),
        ];

        $group = $this->findDependent($this->createService()->canDelete(new Order(1)), OrderItem::class);

        self::assertNotNull($group);
        self::assertSame(3, $group->count);
        self::assertSame([10, 11, 12], $group->ids);
    }

    #[Test]
    public function detach_children_do_not_block_deletion_even_if_marked_blocking(): void
    {
        $this->joinTableReturns = [new Tag(100), new Tag(101)];

        $relations = $this->createService()->analyze(new Order(1));

        self::assertTrue($relations->canDelete, 'detach-связи не должны блокировать удаление Order');
        self::assertCount(1, $relations->childrenDetach);
        self::assertSame([100, 101], $relations->childrenDetach[0]->ids);
        self::assertSame([], $relations->childrenDelete);
    }

    #[Test]
    public function reference_child_without_cascade_is_not_reported_as_dependent(): void
    {
        $this->assocReturns[Invoice::class] = [new Invoice(id: 500, orderId: 1)];

        $result = $this->createService()->canDelete(new Order(1));

        self::assertTrue($result->canDelete);
        self::assertNull(
            $this->findDependent($result, Invoice::class),
            'Сейчас soft-reference-дети молча теряются — зафиксировано в CODE_REVIEW.md',
        );
    }

    #[Test]
    public function it_aggregates_delete_and_detach_children_into_dependents(): void
    {
        $this->assocReturns[OrderItem::class] = [new OrderItem(id: 10, orderId: 1)];
        $this->joinTableReturns               = [new Tag(100)];
        $this->assocReturns[Invoice::class]   = [new Invoice(id: 500, orderId: 1)];

        $result = $this->createService()->canDelete(new Order(1));

        self::assertFalse($result->canDelete);
        self::assertNotNull($this->findDependent($result, OrderItem::class));
        self::assertNotNull($this->findDependent($result, Tag::class));
        self::assertNull($this->findDependent($result, Invoice::class));
    }

    #[Test]
    public function a_blocking_parent_relation_does_not_block_the_child_itself(): void
    {
        $relations = $this->createService()->analyze(new OrderItem(id: 10, orderId: 7));

        self::assertTrue($relations->canDelete);
        self::assertCount(1, $relations->parents);
        self::assertFalse($relations->parents[0]->hard, 'родительская связь всегда hard=false');
        self::assertSame(Order::class, $relations->parents[0]->childClass);
        self::assertSame([7], $relations->parents[0]->ids);
    }

    #[Test]
    public function scalar_fk_that_is_null_produces_no_parent_group(): void
    {
        $relations = $this->createService()->analyze(new OrderItem(id: 10, orderId: null));

        self::assertSame([], $relations->parents);
    }

    #[Test]
    public function scalar_fk_that_is_zero_is_treated_as_absent(): void
    {
        $relations = $this->createService()->analyze(new OrderItem(id: 10, orderId: 0));

        self::assertSame([], $relations->parents);
    }

    #[Test]
    public function it_extracts_parent_ids_from_a_json_array_property(): void
    {
        $relations = $this->createService()->analyze(new Post(id: 1, categoryIds: [10, 20, 30]));

        self::assertCount(1, $relations->parents);
        self::assertSame(Category::class, $relations->parents[0]->childClass);
        self::assertSame(3, $relations->parents[0]->count);
        self::assertSame([10, 20, 30], array_values($relations->parents[0]->ids));
    }

    #[Test]
    public function it_decodes_parent_ids_from_a_json_string_property(): void
    {
        $relations = $this->createService()->analyze(new Post(id: 1, categoryIds: '[10, 20]'));

        self::assertCount(1, $relations->parents);
        self::assertSame([10, 20], array_values($relations->parents[0]->ids));
    }

    #[Test]
    public function invalid_json_string_yields_no_parent_group(): void
    {
        $relations = $this->createService()->analyze(new Post(id: 1, categoryIds: '{ broken json'));

        self::assertSame([], $relations->parents);
    }

    #[Test]
    public function empty_json_array_yields_no_parent_group(): void
    {
        $relations = $this->createService()->analyze(new Post(id: 1, categoryIds: []));

        self::assertSame([], $relations->parents);
    }

    #[Test]
    public function it_finds_blocking_children_referencing_parent_via_json(): void
    {
        $this->jsonContainsReturns = [new Post(id: 55), new Post(id: 56)];

        $relations = $this->createService()->analyze(new Category(9));

        self::assertFalse($relations->canDelete);
        $group = null;
        foreach ($relations->childrenDelete as $g) {
            if ($g->childClass === Post::class) {
                $group = $g;
            }
        }
        self::assertNotNull($group);
        self::assertTrue($group->hard);
        self::assertSame([55, 56], $group->ids);
    }

    #[Test]
    public function association_object_field_throws_a_type_error(): void
    {
        $this->expectException(\TypeError::class);

        $this->createService()->analyze(new Comment(id: 1, parent: new Order(42)));
    }

    #[Test]
    public function it_exposes_child_relation_rules_for_a_parent_class(): void
    {
        $rules = $this->createService()->getChildRelationRules(Order::class);

        $childClasses = array_map(static fn (array $r): string => $r[0], $rules);
        self::assertContains(OrderItem::class, $childClasses);
        self::assertContains(Tag::class, $childClasses);
        self::assertContains(Invoice::class, $childClasses);
    }

    #[Test]
    public function child_relation_rules_are_empty_for_a_class_nobody_depends_on(): void
    {
        self::assertSame([], $this->createService()->getChildRelationRules(OrderItem::class));
    }

    private function createService(): DeletionService
    {
        return new DeletionService($this->createEntityManager(), $this->finder);
    }

    private function createEntityManager(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn(new ArrayMetadataFactory(self::MAPPED_ENTITIES));
        $em->method('getClassMetadata')->willReturnCallback($this->metadataFor(...));

        return $em;
    }

    private function metadataFor(string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        if ($class === Post::class) {
            $metadata->fieldMappings = ['categoryIds' => ['type' => 'json']];
        }

        return $metadata;
    }

    private function createFinder(): GenericReadRepository
    {
        $finder = $this->createMock(GenericReadRepository::class);

        $finder->method('getId')->willReturnCallback(static fn (object $e): int|string|null => $e->id ?? null);

        $finder->method('findByAssociation')
            ->willReturnCallback(fn (string $entityClass): array => $this->assocReturns[$entityClass] ?? []);

        $finder->method('findByJoinTable')
            ->willReturnCallback(fn (): array => $this->joinTableReturns);

        $finder->method('findByJsonContains')
            ->willReturnCallback(fn (): array => $this->jsonContainsReturns);

        return $finder;
    }

    private function findDependent(CanDeleteDto $result, string $childClass): ?object
    {
        foreach ($result->dependents as $group) {
            if ($group->childClass === $childClass) {
                return $group;
            }
        }

        return null;
    }
}
