<?php

declare(strict_types=1);

namespace App\Orders\Application\Query\View;

/**
 * Read-side projection of an order, built straight from SQL rows.
 *
 * Deliberately not an {@see \App\Orders\Domain\Model\Order}: listing orders
 * needs no behaviour, and hydrating entities for a paginated list would load
 * the whole aggregate per row.
 */
final readonly class OrderSummary
{
    /**
     * @param list<OrderItemSummary> $items
     */
    public function __construct(
        public string $id,
        public string $customerId,
        public string $status,
        public int $total,
        public string $currency,
        public \DateTimeImmutable $placedAt,
        public ?\DateTimeImmutable $paidAt,
        public array $items,
    ) {
    }
}
