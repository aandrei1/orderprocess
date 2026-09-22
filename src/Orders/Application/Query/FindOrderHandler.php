<?php

declare(strict_types=1);

namespace App\Orders\Application\Query;

use App\Orders\Application\Port\OrderReadModel;
use App\Orders\Application\Query\View\OrderSummary;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class FindOrderHandler
{
    public function __construct(private readonly OrderReadModel $orders)
    {
    }

    public function __invoke(FindOrder $query): ?OrderSummary
    {
        return $this->orders->findById($query->orderId());
    }
}
