<?php

declare(strict_types=1);

namespace Shared\Deletion\Tests\Fixture;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\DeletionCascade;
use Shared\Deletion\Enum\RelationType;

#[RelationTo(
    entity: Order::class,
    field: 'id',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::DETACH_RELATIONS,
    joinTable: 'order_tag',
    joinColumn: 'order_id',
    inverseJoinColumn: 'tag_id',
)]
final class Tag
{
    public function __construct(public int $id)
    {
    }
}
