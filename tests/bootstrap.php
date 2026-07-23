<?php

declare(strict_types=1);

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefixes = [
            'Shared\\Deletion\\Tests\\' => __DIR__ . '/',
            'Shared\\Deletion\\'        => __DIR__ . '/../src/Deletion/',
        ];

        foreach ($prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    });
}

namespace Doctrine\Persistence\Mapping {
    if (!interface_exists(ClassMetadataFactory::class)) {
        interface ClassMetadataFactory
        {
            /** @return array<int, object> */
            public function getAllMetadata(): array;
        }
    }
}

namespace Doctrine\ORM\Mapping {
    if (!class_exists(ClassMetadata::class)) {
        class ClassMetadata
        {
            /** @var array<string, array<string, mixed>> */
            public array $fieldMappings = [];

            public function __construct(public string $name = '')
            {
            }

            public function getName(): string
            {
                return $this->name;
            }
        }
    }
}

namespace Doctrine\ORM {
    if (!interface_exists(EntityManagerInterface::class)) {
        interface EntityManagerInterface
        {
            public function getMetadataFactory(): \Doctrine\Persistence\Mapping\ClassMetadataFactory;

            public function getClassMetadata(string $className): \Doctrine\ORM\Mapping\ClassMetadata;

            public function createQueryBuilder(): mixed;
        }
    }
}

namespace Shared\Persistence {
    if (!class_exists(GenericReadRepository::class)) {
        class GenericReadRepository
        {
            public function getId(object $entity): int|string|null
            {
                return null;
            }

            /** @return iterable<object> */
            public function findByAssociation(string $entityClass, string $field, object $value): iterable
            {
                return [];
            }

            /** @return iterable<object> */
            public function findByJsonContains(string $entityClass, string $field, mixed $value): iterable
            {
                return [];
            }

            /** @return iterable<object> */
            public function findByJoinTable(string $childClass, string $joinTable, string $joinColumn, string $inverseJoinColumn, object $object): iterable
            {
                return [];
            }
        }
    }
}

namespace Psr\Clock {
    if (!interface_exists(ClockInterface::class)) {
        interface ClockInterface
        {
            public function now(): \DateTimeImmutable;
        }
    }
}
