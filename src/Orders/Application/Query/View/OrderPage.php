<?php

declare(strict_types=1);

namespace App\Orders\Application\Query\View;

/**
 * One page of orders plus the total count, so the UI can render pagination
 * without a second round trip.
 */
final readonly class OrderPage
{
    /**
     * @param list<OrderSummary> $orders
     */
    public function __construct(
        public array $orders,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }
}
