<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Orders\Application\Command\PlaceOrder;
use App\Orders\Application\Command\PlaceOrderHandler;
use App\Orders\Domain\Event\OrderPaid;
use App\Orders\Domain\Event\OrderPlaced;
use App\Orders\Domain\Model\Product;
use App\Orders\Domain\Model\ValueObject\CustomerId;
use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\OrderId;
use App\Orders\Domain\Model\ValueObject\OrderStatus;
use App\Orders\Domain\Model\ValueObject\ProductId;
use App\Orders\Domain\Port\OrderRepository;
use App\Orders\Domain\Port\ProductRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the whole use case against a real PostgreSQL database.
 *
 * The unit test covers the orchestration with mocked ports; everything that
 * only breaks when Doctrine is actually involved — custom DBAL types on
 * identifiers, identity-map hashing, collection hydration, real transaction
 * boundaries — can only be caught here.
 */
final class PlaceOrderHandlerTest extends KernelTestCase
{
    private const string CUSTOMER_ID = '11111111-1111-4111-8111-111111111111';

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private PlaceOrderHandler $handler;
    private ProductRepository $productRepository;
    private OrderRepository $orderRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->handler = $container->get(PlaceOrderHandler::class);
        $this->productRepository = $container->get(ProductRepository::class);
        $this->orderRepository = $container->get(OrderRepository::class);

        // Explicit truncation rather than wrapping each test in a transaction:
        // the use case opens its own transaction, and the rollback test below
        // depends on real commit/rollback semantics, not on the harness's.
        $this->connection->executeStatement('TRUNCATE order_items, orders, products, outbox');
        $this->entityManager->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->entityManager->close();
    }

    public function testPlacingAnOrderPersistsOrderStockAndEventsTogether(): void
    {
        $chocolate = $this->givenProduct('Dark chocolate', price: 4686, stock: 242, minThreshold: 10);
        $raisins = $this->givenProduct('Raisins', price: 25388, stock: 235, minThreshold: 16);

        $orderId = ($this->handler)(new PlaceOrder(
            CustomerId::fromString(self::CUSTOMER_ID),
            [
                ['productId' => $chocolate->toString(), 'quantity' => 3],
                ['productId' => $raisins->toString(), 'quantity' => 2],
            ],
        ));

        $order = $this->fetchOrderRow($orderId);
        self::assertSame(self::CUSTOMER_ID, $order['customer_id']);
        self::assertSame(OrderStatus::PAID->value, $order['status']);
        self::assertNotNull($order['paid_at']);

        $lines = $this->connection->fetchAllAssociative(
            'SELECT product_id, quantity_value, unit_price_amount FROM order_items WHERE order_id = ? ORDER BY unit_price_amount',
            [$orderId->toString()],
        );
        self::assertCount(2, $lines);
        self::assertSame($chocolate->toString(), $lines[0]['product_id']);
        self::assertSame(3, $lines[0]['quantity_value']);
        self::assertSame(4686, $lines[0]['unit_price_amount']);
        self::assertSame($raisins->toString(), $lines[1]['product_id']);
        self::assertSame(2, $lines[1]['quantity_value']);

        // Stock decremented by exactly the ordered quantities, version bumped
        // by the optimistic locking column.
        self::assertSame(239, $this->stockOf($chocolate));
        self::assertSame(233, $this->stockOf($raisins));
        self::assertSame(2, $this->versionOf($chocolate));
        self::assertSame(2, $this->versionOf($raisins));

        // Both domain events landed in the outbox, inside the same transaction.
        $events = $this->connection->fetchAllAssociative('SELECT type, status, payload FROM outbox ORDER BY type');
        self::assertSame([OrderPaid::class, OrderPlaced::class], array_column($events, 'type'));
        self::assertSame(['pending', 'pending'], array_column($events, 'status'));

        $placed = json_decode($events[1]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($orderId->toString(), $placed['orderId']);
        self::assertSame(self::CUSTOMER_ID, $placed['customerId']);
        self::assertSame(3 * 4686 + 2 * 25388, $placed['total']);
        self::assertCount(2, $placed['items']);
    }

    public function testInsufficientStockRollsBackTheWholeOperation(): void
    {
        $chocolate = $this->givenProduct('Dark chocolate', price: 4686, stock: 242, minThreshold: 10);
        $raisins = $this->givenProduct('Raisins', price: 25388, stock: 233, minThreshold: 16);

        try {
            ($this->handler)(new PlaceOrder(
                CustomerId::fromString(self::CUSTOMER_ID),
                [
                    // Decremented first, and must be restored by the rollback.
                    ['productId' => $chocolate->toString(), 'quantity' => 5],
                    ['productId' => $raisins->toString(), 'quantity' => 99999],
                ],
            ));

            self::fail('Expected a DomainException for insufficient stock.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Insufficient stock', $e->getMessage());
        }

        $this->entityManager->clear();

        self::assertSame(242, $this->stockOf($chocolate), 'Stock of the first product must be restored.');
        self::assertSame(233, $this->stockOf($raisins));
        self::assertSame(0, $this->rowCount('orders'));
        self::assertSame(0, $this->rowCount('order_items'));
        self::assertSame(0, $this->rowCount('outbox'), 'No event may survive a rolled-back order.');
    }

    public function testAnOrderCanBeReadBackFromTheDatabase(): void
    {
        $chocolate = $this->givenProduct('Dark chocolate', price: 4686, stock: 242, minThreshold: 10);

        $orderId = ($this->handler)(new PlaceOrder(
            CustomerId::fromString(self::CUSTOMER_ID),
            [['productId' => $chocolate->toString(), 'quantity' => 4]],
        ));

        // Drop the identity map so the order is hydrated from scratch: this is
        // the read path through the custom DBAL types and the item collection.
        $this->entityManager->clear();

        $order = $this->orderRepository->findById($orderId);

        self::assertNotNull($order);
        self::assertTrue($order->id()->equals($orderId));
        self::assertTrue($order->customerId()->equals(CustomerId::fromString(self::CUSTOMER_ID)));
        self::assertSame(OrderStatus::PAID, $order->status());
        self::assertSame(4 * 4686, $order->total()->amount());

        $items = $order->items();
        self::assertCount(1, $items);
        self::assertTrue($items[0]->productId()->equals($chocolate));
        self::assertSame(4, $items[0]->quantity()->toInt());
    }

    public function testUnknownProductIsRejectedWithoutTouchingTheDatabase(): void
    {
        $chocolate = $this->givenProduct('Dark chocolate', price: 4686, stock: 242, minThreshold: 10);

        $this->expectException(\DomainException::class);

        try {
            ($this->handler)(new PlaceOrder(
                CustomerId::fromString(self::CUSTOMER_ID),
                [
                    ['productId' => $chocolate->toString(), 'quantity' => 1],
                    ['productId' => ProductId::generate()->toString(), 'quantity' => 1],
                ],
            ));
        } finally {
            $this->entityManager->clear();

            self::assertSame(242, $this->stockOf($chocolate));
            self::assertSame(0, $this->rowCount('orders'));
            self::assertSame(0, $this->rowCount('outbox'));
        }
    }

    private function givenProduct(string $name, int $price, int $stock, int $minThreshold): ProductId
    {
        $id = ProductId::generate();

        $this->productRepository->save(new Product(
            $id,
            $name,
            Money::fromInt($price),
            $stock,
            $minThreshold,
        ));

        $this->entityManager->clear();

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOrderRow(OrderId $id): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, customer_id, status, placed_at, paid_at FROM orders WHERE id = ?',
            [$id->toString()],
        );

        self::assertIsArray($row, sprintf('Order %s was not persisted.', $id->toString()));

        return $row;
    }

    private function stockOf(ProductId $id): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT stock_quantity FROM products WHERE id = ?',
            [$id->toString()],
        );
    }

    private function versionOf(ProductId $id): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT version FROM products WHERE id = ?',
            [$id->toString()],
        );
    }

    private function rowCount(string $table): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT count(*) FROM %s', $table));
    }
}
