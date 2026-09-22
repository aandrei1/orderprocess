<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Persistence;

use App\Orders\Application\Port\ProductReadModel;
use App\Orders\Application\Query\View\ProductSummary;
use App\Orders\Domain\Model\ValueObject\Money;
use Doctrine\DBAL\Connection;

final class DoctrineProductReadModel implements ProductReadModel
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function inStock(int $limit): array
    {
        /** @var list<array{id: string, name: string, price_amount: int, stock_quantity: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, price_amount, stock_quantity
             FROM products
             WHERE stock_quantity > 0
             ORDER BY name, id
             LIMIT :limit',
            ['limit' => $limit],
        );

        return array_map(
            static fn (array $row): ProductSummary => new ProductSummary(
                $row['id'],
                $row['name'],
                $row['price_amount'],
                Money::CURRENCY_RON,
                $row['stock_quantity'],
            ),
            $rows,
        );
    }
}
