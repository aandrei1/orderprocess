<?php

declare(strict_types=1);

namespace App\Orders\Application\Query;

use App\Orders\Application\Port\ProductReadModel;
use App\Orders\Application\Query\View\ProductSummary;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ListProductsHandler
{
    public function __construct(private readonly ProductReadModel $products)
    {
    }

    /**
     * @return list<ProductSummary>
     */
    public function __invoke(ListProducts $query): array
    {
        return $this->products->inStock($query->limit());
    }
}
