<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Persistence;

use App\Orders\Application\Port\TransactionManager;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction(
            static fn (): mixed => $operation(),
        );
    }
}
