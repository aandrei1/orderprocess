<?php

declare(strict_types=1);

namespace App\Orders\Domain\Event;

interface DomainEvent
{
    public function occurredAt(): \DateTimeImmutable;
}
