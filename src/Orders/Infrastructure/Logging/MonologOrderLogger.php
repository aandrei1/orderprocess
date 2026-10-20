<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Logging;

use App\Orders\Application\Command\PlaceOrder;
use App\Orders\Application\Port\OrderLogger;
use App\Orders\Domain\Exception\InsufficientStock;
use App\Orders\Domain\Exception\PaymentFailed;
use App\Orders\Domain\Exception\ProductNotFound;
use App\Orders\Domain\Model\Order;
use App\Orders\Domain\Model\OrderItem;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

#[WithMonologChannel('orders')]
final class MonologOrderLogger implements OrderLogger
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function orderPlaced(Order $order, float $durationMs): void
    {
        $items = $order->items();

        $this->logger->info('Order placed.', [
            'event' => 'order.placed',
            'order_id' => $order->id()->toString(),
            'customer_id' => $order->customerId()->toString(),
            'items_count' => count($items),
            'total_quantity' => array_sum(array_map(static fn (OrderItem $item): int => $item->quantity()->toInt(), $items)),
            'product_ids' => array_map(static fn (OrderItem $item): string => $item->productId()->toString(), $items),
            'total_amount' => $order->total()->amount(),
            'status' => $order->status()->value,
            'duration_ms' => round($durationMs, 2),
        ]);
    }

    public function orderFailed(PlaceOrder $command, \Throwable $reason, float $durationMs): void
    {
        $items = $command->items();

        $this->logger->log($reason instanceof \DomainException ? 'warning' : 'error', 'Order failed.', [
            'event' => 'order.failed',
            'customer_id' => $command->customerId()->toString(),
            'items_count' => count($items),
            'total_quantity' => array_sum(array_column($items, 'quantity')),
            'product_ids' => array_column($items, 'productId'),
            'failure_reason' => $this->failureReason($reason),
            'exception_class' => $reason::class,
            'message' => $reason->getMessage(),
            'duration_ms' => round($durationMs, 2),
        ]);
    }

    private function failureReason(\Throwable $reason): string
    {
        return match (true) {
            $reason instanceof ProductNotFound => 'product_not_found',
            $reason instanceof InsufficientStock => 'insufficient_stock',
            $reason instanceof PaymentFailed => 'payment_failed',
            $reason instanceof \DomainException => 'domain_rule_violation',
            default => 'unexpected',
        };
    }
}
