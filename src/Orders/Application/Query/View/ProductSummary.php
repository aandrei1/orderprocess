<?php

declare(strict_types=1);

namespace App\Orders\Application\Query\View;

/**
 * Read-side projection of a catalog entry, for the order form.
 */
final readonly class ProductSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public int $price,
        public string $currency,
        public int $stockQuantity,
    ) {
    }
}
