<?php

declare(strict_types=1);

namespace App\Orders\Application\Command;

use App\Orders\Application\Port\DomainEventDispatcher;
use App\Orders\Application\Port\OrderLogger;
use App\Orders\Application\Port\TransactionManager;
use App\Orders\Domain\Exception\PaymentFailed;
use App\Orders\Domain\Exception\ProductNotFound;
use App\Orders\Domain\Model\Order;
use App\Orders\Domain\Model\OrderItem;
use App\Orders\Domain\Model\ValueObject\OrderId;
use App\Orders\Domain\Model\ValueObject\ProductId;
use App\Orders\Domain\Model\ValueObject\Quantity;
use App\Orders\Domain\Port\OrderRepository;
use App\Orders\Domain\Port\PaymentGateway;
use App\Orders\Domain\Port\ProductRepository;

final class PlaceOrderHandler
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ProductRepository $productRepository,
        private readonly PaymentGateway $paymentGateway,
        private readonly DomainEventDispatcher $eventDispatcher,
        private readonly TransactionManager $transactionManager,
        private readonly OrderLogger $logger,
    ) {
    }

    public function __invoke(PlaceOrder $command): OrderId
    {
        $startedAt = hrtime(true);

        // Logged outside the transaction: a line must never describe an order
        // that was rolled back, so "placed" is written only after the commit.
        try {
            $order = $this->transactionManager->transactional(fn (): Order => $this->place($command));
        } catch (\Throwable $e) {
            $this->logger->orderFailed($command, $e, $this->elapsedMs($startedAt));

            throw $e;
        }

        $this->logger->orderPlaced($order, $this->elapsedMs($startedAt));

        return $order->id();
    }

    private function place(PlaceOrder $command): Order
    {
        $occurredAt = new \DateTimeImmutable();
        $items = [];
        $products = [];

        // 1. Check stock + decrement
        foreach ($command->items() as $item) {
            $productId = ProductId::fromString($item['productId']);
            $product = $this->productRepository->findById($productId);

            if (null === $product) {
                throw ProductNotFound::withId($productId);
            }

            $quantity = Quantity::fromInt($item['quantity']);
            $product->decrementStock($quantity, $occurredAt);

            $items[] = new OrderItem($product->id(), $quantity, $product->price());
            $products[] = $product;
        }

        // 2. Build the order
        $order = Order::place(
            OrderId::generate(),
            $command->customerId(),
            $items,
            $occurredAt,
        );

        // 3. Charge on the spot (mock)
        if (!$this->paymentGateway->charge($order->total())) {
            throw PaymentFailed::refused();
        }

        $order->markPaid($occurredAt);

        // 4. Save (same transaction)
        $this->orderRepository->save($order);
        foreach ($products as $product) {
            $this->productRepository->save($product);
        }

        // 5. Dispatch events (outbox, same transaction)
        foreach ($order->releaseEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }

        foreach ($products as $product) {
            foreach ($product->releaseEvents() as $event) {
                $this->eventDispatcher->dispatch($event);
            }
        }

        return $order;
    }

    private function elapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
