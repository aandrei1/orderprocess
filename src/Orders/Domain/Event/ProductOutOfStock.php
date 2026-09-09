<?php

declare(strict_types=1);

namespace App\Orders\Domain\Event;

use App\Orders\Domain\Model\ValueObject\ProductId;

final class ProductOutOfStock implements DomainEvent
{
    public function __construct(
        private readonly ProductId $productId,
        private readonly \DateTimeImmutable $occurredAt,
    ) {
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
