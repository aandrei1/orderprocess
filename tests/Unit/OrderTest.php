<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Orders\Domain\Event\OrderCancelled;
use App\Orders\Domain\Event\OrderPaid;
use App\Orders\Domain\Event\OrderPlaced;
use App\Orders\Domain\Model\Order;
use App\Orders\Domain\Model\OrderItem;
use App\Orders\Domain\Model\ValueObject\CustomerId;
use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\OrderId;
use App\Orders\Domain\Model\ValueObject\OrderStatus;
use App\Orders\Domain\Model\ValueObject\ProductId;
use App\Orders\Domain\Model\ValueObject\Quantity;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    private function createOrder(): Order
    {
        $item = new OrderItem(
            ProductId::fromString('11111111-1111-1111-1111-111111111111'),
            Quantity::fromInt(2),
            Money::fromInt(500),
        );

        return Order::place(
            OrderId::fromString('22222222-2222-2222-2222-222222222222'),
            CustomerId::fromString('33333333-3333-3333-3333-333333333333'),
            [$item],
            new \DateTimeImmutable('2026-01-01 10:00:00'),
        );
    }

    public function testPlaceCreatesPlacedOrderWithEvent(): void
    {
        $order = $this->createOrder();

        self::assertSame(OrderStatus::PLACED, $order->status());
        self::assertSame(1000, $order->total()->amount());

        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrderPlaced::class, $events[0]);
    }

    public function testTotalCalculatesSumOfItems(): void
    {
        $order = $this->createOrder();

        self::assertSame(1000, $order->total()->amount());
    }

    public function testAddItemWithDuplicateProductThrows(): void
    {
        $order = $this->createOrder();

        $duplicate = new OrderItem(
            ProductId::fromString('11111111-1111-1111-1111-111111111111'),
            Quantity::fromInt(1),
            Money::fromInt(100),
        );

        $this->expectException(\DomainException::class);

        $order->addItem($duplicate);
    }

    public function testMarkPaidTransitionsToPaid(): void
    {
        $order = $this->createOrder();
        $order->markPaid(new \DateTimeImmutable('2026-01-01 10:05:00'));

        self::assertSame(OrderStatus::PAID, $order->status());
        self::assertNotNull($order->paidAt());

        $events = $order->releaseEvents();
        self::assertInstanceOf(OrderPaid::class, $events[1]);
    }

    public function testMarkPaidOnPaidOrderThrows(): void
    {
        $order = $this->createOrder();
        $order->markPaid(new \DateTimeImmutable('2026-01-01 10:05:00'));

        $this->expectException(\DomainException::class);

        $order->markPaid(new \DateTimeImmutable('2026-01-01 10:10:00'));
    }

    public function testCancelPlacedOrder(): void
    {
        $order = $this->createOrder();
        $order->cancel('customer changed mind', new \DateTimeImmutable('2026-01-01 10:05:00'));

        self::assertSame(OrderStatus::CANCELLED, $order->status());

        $events = $order->releaseEvents();
        self::assertInstanceOf(OrderCancelled::class, $events[1]);
    }

    public function testCancelPaidOrderThrows(): void
    {
        $order = $this->createOrder();
        $order->markPaid(new \DateTimeImmutable('2026-01-01 10:05:00'));

        $this->expectException(\DomainException::class);

        $order->cancel('refund', new \DateTimeImmutable('2026-01-01 10:10:00'));
    }

    public function testCancelCancelledOrderThrows(): void
    {
        $order = $this->createOrder();
        $order->cancel('first', new \DateTimeImmutable('2026-01-01 10:05:00'));

        $this->expectException(\DomainException::class);

        $order->cancel('second', new \DateTimeImmutable('2026-01-01 10:10:00'));
    }
}
