<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Orders\Domain\Event\OrderPaid;
use App\Orders\Domain\Event\OrderPlaced;
use App\Orders\Domain\Event\StockBelowThreshold;
use App\Orders\Domain\Model\ValueObject\CustomerId;
use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\OrderId;
use App\Orders\Domain\Model\ValueObject\ProductId;
use App\Orders\Infrastructure\Messaging\OutboxDomainEventDispatcher;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class OutboxDomainEventDispatcherTest extends TestCase
{
    /**
     * Regression test: event fields are private readonly promoted properties, so a
     * get_object_vars()-based serialization wrote an empty "{}" payload. The
     * dispatcher must persist the real content.
     */
    public function testDispatchWritesNonEmptyPayload(): void
    {
        $inserted = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$inserted): int {
                $inserted['table'] = $table;
                $inserted['data'] = $data;

                return 1;
            },
        );

        new OutboxDomainEventDispatcher($connection)->dispatch(new OrderPlaced(
            OrderId::fromString('22222222-2222-2222-2222-222222222222'),
            CustomerId::fromString('33333333-3333-3333-3333-333333333333'),
            [['productId' => '11111111-1111-1111-1111-111111111111', 'quantity' => 2, 'unitPrice' => 5000]],
            Money::fromInt(10000),
            new \DateTimeImmutable('2026-09-01 10:00:00'),
        ));

        self::assertSame('outbox', $inserted['table']);

        $row = $inserted['data'];
        self::assertSame(OrderPlaced::class, $row['type']);
        self::assertSame('pending', $row['status']);
        self::assertSame('2026-09-01 10:00:00.000000', $row['occurred_at']);

        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('22222222-2222-2222-2222-222222222222', $payload['orderId']);
        self::assertSame('33333333-3333-3333-3333-333333333333', $payload['customerId']);
        self::assertSame(10000, $payload['total']);
        self::assertSame([['productId' => '11111111-1111-1111-1111-111111111111', 'quantity' => 2, 'unitPrice' => 5000]], $payload['items']);
        self::assertSame('2026-09-01 10:00:00.000000', $payload['occurredAt']);
    }

    public function testDispatchSerializesOrderPaid(): void
    {
        $payloads = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->willReturnCallback(
            static function (string $_table, array $data) use (&$payloads): int {
                $payloads[] = $data['payload'];

                return 1;
            },
        );

        new OutboxDomainEventDispatcher($connection)->dispatch(new OrderPaid(
            OrderId::fromString('22222222-2222-2222-2222-222222222222'),
            Money::fromInt(10000),
            new \DateTimeImmutable('2026-09-01 10:05:00'),
        ));

        $payload = json_decode($payloads[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('22222222-2222-2222-2222-222222222222', $payload['orderId']);
        self::assertSame(10000, $payload['total']);
        self::assertSame('2026-09-01 10:05:00.000000', $payload['occurredAt']);
        self::assertArrayNotHasKey('items', $payload);
    }

    public function testDispatchSerializesStockBelowThreshold(): void
    {
        $payloads = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->willReturnCallback(
            static function (string $_table, array $data) use (&$payloads): int {
                $payloads[] = $data['payload'];

                return 1;
            },
        );

        new OutboxDomainEventDispatcher($connection)->dispatch(new StockBelowThreshold(
            ProductId::fromString('11111111-1111-1111-1111-111111111111'),
            2,
            3,
            new \DateTimeImmutable('2026-09-01 10:00:00'),
        ));

        $payload = json_decode($payloads[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('11111111-1111-1111-1111-111111111111', $payload['productId']);
        self::assertSame(2, $payload['currentStock']);
        self::assertSame(3, $payload['minThreshold']);
    }
}
