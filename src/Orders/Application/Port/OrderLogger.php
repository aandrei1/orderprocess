<?php

declare(strict_types=1);

namespace App\Orders\Application\Port;

use App\Orders\Application\Command\PlaceOrder;
use App\Orders\Domain\Model\Order;

interface OrderLogger
{
    public function orderPlaced(Order $order, float $durationMs): void;

    public function orderFailed(PlaceOrder $command, \Throwable $reason, float $durationMs): void;
}
