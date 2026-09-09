<?php

declare(strict_types=1);

namespace App\Orders\Domain\Event;

use App\Orders\Domain\Model\ValueObject\OrderId;

final class OrderCancelled implements DomainEvent
{
    public function __construct(
        private readonly OrderId $orderId,
        private readonly string $reason,
        private readonly \DateTimeImmutable $occurredAt,
    ) {
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
