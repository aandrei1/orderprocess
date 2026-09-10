<?php

declare(strict_types=1);

namespace App\Orders\Application\Port;

interface TransactionManager
{
    /**
     * Runs the operation inside a single database transaction: everything the
     * operation persists (entities and outbox rows alike) commits atomically,
     * or nothing does.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
