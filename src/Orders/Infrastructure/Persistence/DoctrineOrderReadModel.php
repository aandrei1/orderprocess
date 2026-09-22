<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Persistence;

use App\Orders\Application\Port\OrderReadModel;
use App\Orders\Application\Query\View\OrderItemSummary;
use App\Orders\Application\Query\View\OrderPage;
use App\Orders\Application\Query\View\OrderSummary;
use App\Orders\Domain\Model\ValueObject\Money;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Read side over plain SQL: no ORM hydration, no identity map, no lazy
 * collections. A listing page must not pay for the write model.
 */
final class DoctrineOrderReadModel implements OrderReadModel
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function page(int $page, int $perPage): OrderPage
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM orders');

        if (0 === $total) {
            return new OrderPage([], 0, $page, $perPage);
        }

        /** @var list<array{id: string, customer_id: string, status: string, placed_at: string, paid_at: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, customer_id, status, placed_at, paid_at
             FROM orders
             ORDER BY placed_at DESC, id DESC
             LIMIT :limit OFFSET :offset',
            ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return new OrderPage(
            $this->hydrate($rows),
            $total,
            $page,
            $perPage,
        );
    }

    public function findById(string $orderId): ?OrderSummary
    {
        /** @var array{id: string, customer_id: string, status: string, placed_at: string, paid_at: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT id, customer_id, status, placed_at, paid_at FROM orders WHERE id = :id',
            ['id' => $orderId],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrate([$row])[0];
    }

    /**
     * @param list<array{id: string, customer_id: string, status: string, placed_at: string, paid_at: string|null}> $rows
     *
     * @return list<OrderSummary>
     */
    private function hydrate(array $rows): array
    {
        $itemsByOrder = $this->itemsFor(array_column($rows, 'id'));
        $summaries = [];

        foreach ($rows as $row) {
            $items = $itemsByOrder[$row['id']] ?? [];
            $total = 0;

            foreach ($items as $item) {
                $total += $item->subtotal;
            }

            $summaries[] = new OrderSummary(
                $row['id'],
                $row['customer_id'],
                $row['status'],
                $total,
                // The schema stores an amount only: the write side pins every
                // Money to the same currency, so the read side does too.
                Money::CURRENCY_RON,
                new \DateTimeImmutable($row['placed_at']),
                null !== $row['paid_at'] ? new \DateTimeImmutable($row['paid_at']) : null,
                $items,
            );
        }

        return $summaries;
    }

    /**
     * One query for the whole page instead of one per order.
     *
     * @param list<string> $orderIds
     *
     * @return array<string, list<OrderItemSummary>>
     */
    private function itemsFor(array $orderIds): array
    {
        if ([] === $orderIds) {
            return [];
        }

        /** @var list<array{order_id: string, product_id: string, name: string|null, quantity_value: int, unit_price_amount: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT oi.order_id, oi.product_id, p.name, oi.quantity_value, oi.unit_price_amount
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id IN (:ids)
             ORDER BY oi.id',
            ['ids' => $orderIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row['order_id']][] = new OrderItemSummary(
                $row['product_id'],
                // A product row can be missing if the catalog was pruned; the
                // order line stays valid, it just has no name to show.
                $row['name'] ?? $row['product_id'],
                $row['quantity_value'],
                $row['unit_price_amount'],
                $row['quantity_value'] * $row['unit_price_amount'],
            );
        }

        return $grouped;
    }
}
