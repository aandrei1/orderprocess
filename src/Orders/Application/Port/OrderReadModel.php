<?php

declare(strict_types=1);

namespace App\Orders\Application\Port;

use App\Orders\Application\Query\View\OrderPage;
use App\Orders\Application\Query\View\OrderSummary;

/**
 * Read side of orders.
 *
 * Lives in Application/Port rather than Domain/Port because it returns view
 * models, which are an application concern: the domain knows aggregates, not
 * projections. The write side keeps its own port,
 * {@see \App\Orders\Domain\Port\OrderRepository}.
 */
interface OrderReadModel
{
    /**
     * @param positive-int $page
     * @param positive-int $perPage
     */
    public function page(int $page, int $perPage): OrderPage;

    public function findById(string $orderId): ?OrderSummary;
}
