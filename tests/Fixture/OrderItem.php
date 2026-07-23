<?php

declare(strict_types=1);

namespace Shared\Deletion\Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\DeletionCascade;
use Shared\Deletion\Enum\RelationType;

#[RelationTo(
    entity: Order::class,
    field: 'orderId',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::DELETE_CHILD,
)]
final class OrderItem
{
    public function __construct(
        public int $id,
        public ?int $orderId = null,
    ) {
    }
}
