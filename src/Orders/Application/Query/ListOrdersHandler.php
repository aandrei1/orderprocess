<?php

declare(strict_types=1);

namespace App\Orders\Application\Query;

use App\Orders\Application\Port\OrderReadModel;
use App\Orders\Application\Query\View\OrderPage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ListOrdersHandler
{
    public function __construct(private readonly OrderReadModel $orders)
    {
    }

    public function __invoke(ListOrders $query): OrderPage
    {
        return $this->orders->page($query->page(), $query->perPage());
    }
}
