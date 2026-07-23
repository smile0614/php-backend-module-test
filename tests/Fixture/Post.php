<?php

declare(strict_types=1);

namespace Shared\Deletion\Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\DeletionCascade;
use Shared\Deletion\Enum\RelationType;

#[RelationTo(
    entity: Category::class,
    field: 'categoryIds',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::NONE,
)]
final class Post
{
    /**
     * @param array<int, int|string>|string|null $categoryIds JSON-массив id категорий
     */
    public function __construct(
        public int $id,
        public array|string|null $categoryIds = null,
    ) {
    }
}
