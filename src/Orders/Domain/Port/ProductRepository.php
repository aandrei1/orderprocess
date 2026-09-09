<?php

declare(strict_types=1);

namespace App\Orders\Domain\Port;

use App\Orders\Domain\Model\Product;
use App\Orders\Domain\Model\ValueObject\ProductId;

interface ProductRepository
{
    public function save(Product $product): void;

    public function findById(ProductId $id): ?Product;
}
