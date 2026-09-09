<?php

declare(strict_types=1);

namespace App\Orders\Application\Port;

use App\Orders\Domain\Event\DomainEvent;

interface DomainEventDispatcher
{
    public function dispatch(DomainEvent $event): void;
}
