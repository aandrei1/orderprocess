<?php

declare(strict_types=1);

namespace App\Orders\Domain\Exception;

use App\Orders\Domain\Model\ValueObject\ProductId;

final class ProductNotFound extends \DomainException
{
    public static function withId(ProductId $productId): self
    {
        return new self(sprintf('Product %s not found.', $productId->toString()));
    }
}
