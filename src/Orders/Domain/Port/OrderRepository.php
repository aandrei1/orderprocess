<?php

declare(strict_types=1);

namespace App\Orders\Domain\Port;

use App\Orders\Domain\Model\Order;
use App\Orders\Domain\Model\ValueObject\OrderId;

interface OrderRepository
{
    public function save(Order $order): void;

    public function findById(OrderId $id): ?Order;
}
