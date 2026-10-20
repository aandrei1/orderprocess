<?php

declare(strict_types=1);

namespace App\Orders\Domain\Exception;

use App\Orders\Domain\Model\ValueObject\ProductId;

final class InsufficientStock extends \DomainException
{
    public static function forProduct(ProductId $productId, int $requested, int $available): self
    {
        return new self(sprintf('Insufficient stock for product %s: requested %d, available %d.', $productId->toString(), $requested, $available));
    }
}
