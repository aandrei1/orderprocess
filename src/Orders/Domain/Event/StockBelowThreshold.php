<?php

declare(strict_types=1);

namespace App\Orders\Domain\Event;

use App\Orders\Domain\Model\ValueObject\ProductId;

final class StockBelowThreshold implements DomainEvent
{
    public function __construct(
        private readonly ProductId $productId,
        private readonly int $currentStock,
        private readonly int $minThreshold,
        private readonly \DateTimeImmutable $occurredAt,
    ) {
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function currentStock(): int
    {
        return $this->currentStock;
    }

    public function minThreshold(): int
    {
        return $this->minThreshold;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
