<?php

declare(strict_types=1);

namespace Shared\Deletion\Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\RelationType;

#[RelationTo(
    entity: Order::class,
    field: 'parent',
    type: RelationType::REFERENCE,
)]
final class Comment
{
    public function __construct(
        public int $id,
        public ?Order $parent = null,
    ) {
    }
}
