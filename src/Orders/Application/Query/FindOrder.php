<?php

declare(strict_types=1);

namespace App\Orders\Application\Query;

final class FindOrder
{
    public function __construct(private readonly string $orderId)
    {
    }

    public function orderId(): string
    {
        return $this->orderId;
    }
}
