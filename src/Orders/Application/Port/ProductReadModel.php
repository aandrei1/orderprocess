<?php

declare(strict_types=1);

namespace App\Orders\Application\Port;

use App\Orders\Application\Query\View\ProductSummary;

/**
 * Read side of the product catalog — what the order form needs to offer a
 * choice. The write side keeps its own port,
 * {@see \App\Orders\Domain\Port\ProductRepository}.
 */
interface ProductReadModel
{
    /**
     * Products that can still be ordered, cheapest identifiers first.
     *
     * @param positive-int $limit
     *
     * @return list<ProductSummary>
     */
    public function inStock(int $limit): array;
}
