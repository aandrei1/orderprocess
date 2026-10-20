<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Orders\Application\Command\PlaceOrder;
use App\Orders\Domain\Exception\InsufficientStock;
use App\Orders\Domain\Exception\PaymentFailed;
use App\Orders\Domain\Exception\ProductNotFound;
use App\Orders\Domain\Model\Order;
use App\Orders\Domain\Model\OrderItem;
use App\Orders\Domain\Model\ValueObject\CustomerId;
use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\OrderId;
use App\Orders\Domain\Model\ValueObject\ProductId;
use App\Orders\Domain\Model\ValueObject\Quantity;
use App\Orders\Infrastructure\Logging\MonologOrderLogger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class MonologOrderLoggerTest extends TestCase
{
    private const string CUSTOMER_ID = '33333333-3333-3333-3333-333333333333';
    private const string PRODUCT_A = '11111111-1111-1111-1111-111111111111';
    private const string PRODUCT_B = '22222222-2222-2222-2222-222222222222';

    private TestHandler $handler;
    private MonologOrderLogger $logger;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->logger = new MonologOrderLogger(new Logger('orders', [$this->handler]));
    }

    public function testPlacedOrderLogsTheEssentialFieldsAtInfoLevel(): void
    {
        $order = Order::place(
            OrderId::generate(),
            CustomerId::fromString(self::CUSTOMER_ID),
            [
                new OrderItem(ProductId::fromString(self::PRODUCT_A), Quantity::fromInt(3), Money::fromInt(1000)),
                new OrderItem(ProductId::fromString(self::PRODUCT_B), Quantity::fromInt(2), Money::fromInt(500)),
            ],
            new \DateTimeImmutable(),
        );
        $order->markPaid(new \DateTimeImmutable());

        $this->logger->orderPlaced($order, 12.3456);

        self::assertCount(1, $this->handler->getRecords());
        $record = $this->handler->getRecords()[0];

        self::assertSame(Level::Info, $record->level);
        self::assertSame([
            'event' => 'order.placed',
            'order_id' => $order->id()->toString(),
            'customer_id' => self::CUSTOMER_ID,
            'items_count' => 2,
            'total_quantity' => 5,
            'product_ids' => [self::PRODUCT_A, self::PRODUCT_B],
            'total_amount' => 4000,
            'status' => 'paid',
            'duration_ms' => 12.35,
        ], $record->context);
    }

    /**
     * @return iterable<string, array{\Throwable, string, Level}>
     */
    public static function failures(): iterable
    {
        yield 'unknown product' => [ProductNotFound::withId(ProductId::fromString(self::PRODUCT_A)), 'product_not_found', Level::Warning];
        yield 'not enough stock' => [InsufficientStock::forProduct(ProductId::fromString(self::PRODUCT_A), 6, 5), 'insufficient_stock', Level::Warning];
        yield 'payment refused' => [PaymentFailed::refused(), 'payment_failed', Level::Warning];
        yield 'other domain rule' => [new \DomainException('Quantity must be a positive integer.'), 'domain_rule_violation', Level::Warning];
        yield 'infrastructure failure' => [new \RuntimeException('Connection lost.'), 'unexpected', Level::Error];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testFailedOrderLogsAReasonAndASeverityMatchingTheCause(\Throwable $cause, string $reason, Level $level): void
    {
        $command = new PlaceOrder(
            CustomerId::fromString(self::CUSTOMER_ID),
            [
                ['productId' => self::PRODUCT_A, 'quantity' => 6],
                ['productId' => self::PRODUCT_B, 'quantity' => 1],
            ],
        );

        $this->logger->orderFailed($command, $cause, 4.0);

        $record = $this->handler->getRecords()[0];

        self::assertSame($level, $record->level);
        self::assertSame('order.failed', $record->context['event']);
        self::assertSame($reason, $record->context['failure_reason']);
        self::assertSame($cause::class, $record->context['exception_class']);
        self::assertSame($cause->getMessage(), $record->context['message']);
        self::assertSame(self::CUSTOMER_ID, $record->context['customer_id']);
        self::assertSame(2, $record->context['items_count']);
        self::assertSame(7, $record->context['total_quantity']);
        self::assertSame([self::PRODUCT_A, self::PRODUCT_B], $record->context['product_ids']);
        self::assertArrayNotHasKey('order_id', $record->context);
    }
}
