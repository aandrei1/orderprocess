<?php

declare(strict_types=1);

namespace App\Orders\Domain\Model;

use App\Orders\Domain\Event\DomainEvent;
use App\Orders\Domain\Event\OrderCancelled;
use App\Orders\Domain\Event\OrderPaid;
use App\Orders\Domain\Event\OrderPlaced;
use App\Orders\Domain\Model\ValueObject\CustomerId;
use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\OrderId;
use App\Orders\Domain\Model\ValueObject\OrderStatus;

final class Order
{
    /** @var list<OrderItem> */
    private array $items = [];

    /** @var list<DomainEvent> */
    private array $domainEvents = [];

    private function __construct(
        private readonly OrderId $id,
        private readonly CustomerId $customerId,
        private OrderStatus $status,
        private readonly \DateTimeImmutable $placedAt,
        private ?\DateTimeImmutable $paidAt = null,
    ) {
    }

    /**
     * @param list<OrderItem> $items
     */
    public static function place(
        OrderId $id,
        CustomerId $customerId,
        array $items,
        \DateTimeImmutable $occurredAt,
    ): self {
        $order = new self($id, $customerId, OrderStatus::PLACED, $occurredAt);

        foreach ($items as $item) {
            $order->addItem($item);
        }

        $order->recordEvent(new OrderPlaced(
            $id,
            $customerId,
            $order->itemsSnapshot(),
            $order->total(),
            $occurredAt,
        ));

        return $order;
    }

    public function addItem(OrderItem $item): void
    {
        foreach ($this->items as $existing) {
            if ($existing->productId()->equals($item->productId())) {
                throw new \DomainException('Product already exists in the order.');
            }
        }

        $item->setOrder($this);
        $this->items[] = $item;
    }

    public function markPaid(\DateTimeImmutable $occurredAt): void
    {
        if (OrderStatus::PLACED !== $this->status) {
            throw new \DomainException('Only a placed order can be marked as paid.');
        }

        $this->status = OrderStatus::PAID;
        $this->paidAt = $occurredAt;

        $this->recordEvent(new OrderPaid($this->id, $this->total(), $occurredAt));
    }

    public function cancel(string $reason, \DateTimeImmutable $occurredAt): void
    {
        if (OrderStatus::PAID === $this->status) {
            throw new \DomainException('A paid order cannot be cancelled.');
        }

        if (OrderStatus::CANCELLED === $this->status) {
            throw new \DomainException('Order is already cancelled.');
        }

        $this->status = OrderStatus::CANCELLED;

        $this->recordEvent(new OrderCancelled($this->id, $reason, $occurredAt));
    }

    public function total(): Money
    {
        $total = Money::fromInt(0);

        foreach ($this->items as $item) {
            $total = $total->add($item->subtotal());
        }

        return $total;
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    public function customerId(): CustomerId
    {
        return $this->customerId;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function placedAt(): \DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function paidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    /**
     * @return list<OrderItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    /**
     * @return list<array{productId: string, quantity: int, unitPrice: int}>
     */
    private function itemsSnapshot(): array
    {
        $snapshot = [];

        foreach ($this->items as $item) {
            $snapshot[] = [
                'productId' => $item->productId()->toString(),
                'quantity' => $item->quantity()->toInt(),
                'unitPrice' => $item->unitPrice()->amount(),
            ];
        }

        return $snapshot;
    }

    private function recordEvent(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }
}
