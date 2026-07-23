<?php

declare(strict_types=1);

namespace Shared\Deletion\Tests\Support;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;

final class ArrayMetadataFactory implements ClassMetadataFactory
{
    /**
     * @param list<class-string> $classNames
     */
    public function __construct(private readonly array $classNames)
    {
    }

    /**
     * @return array<int, ClassMetadata>
     */
    public function getAllMetadata(): array
    {
        return array_map(static fn (string $fqcn): ClassMetadata => new ClassMetadata($fqcn), $this->classNames);
    }
}
