<?php

declare(strict_types=1);

namespace App\Orders\Application\Command;

use App\Orders\Application\Port\DomainEventDispatcher;
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
    ) {
    }

    public function __invoke(PlaceOrder $command): OrderId
    {
        $occurredAt = new \DateTimeImmutable();
        $items = [];
        $products = [];

        // 1. Verificare stoc + decrement (atomic, în aceeași tranzacție)
        foreach ($command->items() as $item) {
            $product = $this->productRepository->findById(ProductId::fromString($item['productId']));

            if (null === $product) {
                throw new \DomainException(sprintf('Product %s not found.', $item['productId']));
            }

            $quantity = Quantity::fromInt($item['quantity']);
            $product->decrementStock($quantity, $occurredAt);

            $items[] = new OrderItem($product->id(), $quantity, $product->price());
            $products[] = $product;
        }

        // 2. Construire comandă
        $order = Order::place(
            OrderId::generate(),
            $command->customerId(),
            $items,
            $occurredAt,
        );

        // 3. Plată pe loc (mock)
        if (!$this->paymentGateway->charge($order->total())) {
            throw new \DomainException('Payment failed.');
        }

        $order->markPaid($occurredAt);

        // 4. Salvare (aceeași tranzacție)
        $this->orderRepository->save($order);
        foreach ($products as $product) {
            $this->productRepository->save($product);
        }

        // 5. Dispatch evenimente
        foreach ($order->releaseEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }

        foreach ($products as $product) {
            foreach ($product->releaseEvents() as $event) {
                $this->eventDispatcher->dispatch($event);
            }
        }

        return $order->id();
    }
}
