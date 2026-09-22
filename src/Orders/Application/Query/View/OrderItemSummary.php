<?php

declare(strict_types=1);

namespace App\Orders\Application\Query\View;

/**
 * Read-side projection of an order line. Plain scalars: the read side never
 * hydrates entities, so it carries no value objects either.
 */
final readonly class OrderItemSummary
{
    public function __construct(
        public string $productId,
        public string $productName,
        public int $quantity,
        public int $unitPrice,
        public int $subtotal,
    ) {
    }
}
