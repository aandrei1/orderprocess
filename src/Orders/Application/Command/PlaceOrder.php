<?php

declare(strict_types=1);

namespace App\Orders\Application\Command;

use App\Orders\Domain\Model\ValueObject\CustomerId;

final class PlaceOrder
{
    /**
     * @param list<array{productId: string, quantity: int}> $items
     */
    public function __construct(
        private readonly CustomerId $customerId,
        private readonly array $items,
    ) {
    }

    public function customerId(): CustomerId
    {
        return $this->customerId;
    }

    /**
     * @return list<array{productId: string, quantity: int}>
     */
    public function items(): array
    {
        return $this->items;
    }
}
