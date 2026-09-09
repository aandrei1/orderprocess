<?php

declare(strict_types=1);

namespace App\Orders\Domain\Event;

use App\Orders\Domain\Model\ValueObject\CustomerId;
use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\OrderId;

final class OrderPlaced implements DomainEvent
{
    /**
     * @param list<array{productId: string, quantity: int, unitPrice: int}> $items
     */
    public function __construct(
        private readonly OrderId $orderId,
        private readonly CustomerId $customerId,
        private readonly array $items,
        private readonly Money $total,
        private readonly \DateTimeImmutable $occurredAt,
    ) {
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }

    public function customerId(): CustomerId
    {
        return $this->customerId;
    }

    /**
     * @return list<array{productId: string, quantity: int, unitPrice: int}>
     */
    public function items(): array
    {
        return $this->items;
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
