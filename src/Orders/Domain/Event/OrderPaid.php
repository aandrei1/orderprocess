<?php

declare(strict_types=1);

namespace App\Orders\Domain\Event;

use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\OrderId;

final class OrderPaid implements DomainEvent
{
    public function __construct(
        private readonly OrderId $orderId,
        private readonly Money $total,
        private readonly \DateTimeImmutable $occurredAt,
    ) {
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }

    public function total(): Money
    {
        return $this->total;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
