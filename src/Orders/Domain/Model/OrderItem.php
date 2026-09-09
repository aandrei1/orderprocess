<?php

declare(strict_types=1);

namespace App\Orders\Domain\Model;

use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\ProductId;
use App\Orders\Domain\Model\ValueObject\Quantity;

final class OrderItem
{
    private ?int $id = null;

    private ?Order $order = null;

    public function __construct(
        private readonly ProductId $productId,
        private readonly Quantity $quantity,
        private readonly Money $unitPrice,
    ) {
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function quantity(): Quantity
    {
        return $this->quantity;
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function subtotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity->toInt());
    }

    public function setOrder(Order $order): void
    {
        $this->order = $order;
    }
}
