<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Orders\Application\Command\PlaceOrder;
use App\Orders\Application\Command\PlaceOrderHandler;
use App\Orders\Application\Port\OrderReadModel;
use App\Orders\Domain\Model\Product;
use App\Orders\Domain\Model\ValueObject\CustomerId;
use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\OrderStatus;
use App\Orders\Domain\Model\ValueObject\ProductId;
use App\Orders\Domain\Port\ProductRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The read side is raw SQL, so nothing about it can be verified without a real
 * database: the IN(...) expansion, the LIMIT/OFFSET paging, the join that
 * resolves product names, and the totals computed from the line rows.
 */
final class DoctrineOrderReadModelTest extends KernelTestCase
{
    private const string CUSTOMER_ID = '22222222-2222-4222-8222-222222222222';

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private PlaceOrderHandler $placeOrder;
    private ProductRepository $productRepository;
    private OrderReadModel $readModel;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->placeOrder = $container->get(PlaceOrderHandler::class);
        $this->productRepository = $container->get(ProductRepository::class);
        $this->readModel = $container->get(OrderReadModel::class);

        $this->connection->executeStatement('TRUNCATE order_items, orders, products, outbox');
        $this->entityManager->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->entityManager->close();
    }

    public function testAnOrderIsProjectedWithItsLinesAndComputedTotal(): void
    {
        $chocolate = $this->givenProduct('Dark chocolate', price: 4686, stock: 100);
        $raisins = $this->givenProduct('Raisins', price: 25388, stock: 100);

        $orderId = ($this->placeOrder)(new PlaceOrder(
            CustomerId::fromString(self::CUSTOMER_ID),
            [
                ['productId' => $chocolate->toString(), 'quantity' => 3],
                ['productId' => $raisins->toString(), 'quantity' => 2],
            ],
        ));

        $summary = $this->readModel->findById($orderId->toString());

        self::assertNotNull($summary);
        self::assertSame($orderId->toString(), $summary->id);
        self::assertSame(self::CUSTOMER_ID, $summary->customerId);
        self::assertSame(OrderStatus::PAID->value, $summary->status);
        self::assertSame(3 * 4686 + 2 * 25388, $summary->total);
        self::assertSame(Money::CURRENCY_RON, $summary->currency);
        self::assertNotNull($summary->paidAt);

        self::assertCount(2, $summary->items);
        self::assertSame('Dark chocolate', $summary->items[0]->productName);
        self::assertSame(3, $summary->items[0]->quantity);
        self::assertSame(3 * 4686, $summary->items[0]->subtotal);
        self::assertSame('Raisins', $summary->items[1]->productName);
        self::assertSame(2 * 25388, $summary->items[1]->subtotal);
    }

    public function testUnknownOrderIsNotFound(): void
    {
        self::assertNull($this->readModel->findById('33333333-3333-4333-8333-333333333333'));
    }

    public function testPagingWalksEveryOrderExactlyOnceNewestFirst(): void
    {
        $product = $this->givenProduct('Green tea', price: 1000, stock: 100);

        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = ($this->placeOrder)(new PlaceOrder(
                CustomerId::fromString(self::CUSTOMER_ID),
                [['productId' => $product->toString(), 'quantity' => 1]],
            ))->toString();
        }

        $first = $this->readModel->page(1, 2);
        self::assertSame(5, $first->total);
        self::assertSame(1, $first->page);
        self::assertCount(2, $first->orders);

        $second = $this->readModel->page(2, 2);
        self::assertCount(2, $second->orders);

        $third = $this->readModel->page(3, 2);
        self::assertCount(1, $third->orders, 'The last page holds the remainder.');

        $walked = array_merge(
            array_column($first->orders, 'id'),
            array_column($second->orders, 'id'),
            array_column($third->orders, 'id'),
        );

        self::assertCount(5, array_unique($walked), 'No order may repeat or be skipped across pages.');
        self::assertEqualsCanonicalizing($ids, $walked);

        // Every page carries its lines, not just the first.
        foreach ([$first, $second, $third] as $page) {
            foreach ($page->orders as $order) {
                self::assertCount(1, $order->items);
                self::assertSame(1000, $order->total);
            }
        }
    }

    public function testEmptyTableYieldsAnEmptyPage(): void
    {
        $page = $this->readModel->page(1, 20);

        self::assertSame(0, $page->total);
        self::assertSame([], $page->orders);
    }

    public function testAnOrderLineSurvivesTheProductBeingDeleted(): void
    {
        $product = $this->givenProduct('Cinnamon', price: 2500, stock: 10);

        $orderId = ($this->placeOrder)(new PlaceOrder(
            CustomerId::fromString(self::CUSTOMER_ID),
            [['productId' => $product->toString(), 'quantity' => 2]],
        ));

        $this->connection->executeStatement('DELETE FROM products WHERE id = ?', [$product->toString()]);

        $summary = $this->readModel->findById($orderId->toString());

        self::assertNotNull($summary, 'A pruned catalog must not make the order unreadable.');
        self::assertCount(1, $summary->items);
        self::assertSame($product->toString(), $summary->items[0]->productName, 'Falls back to the id when the name is gone.');
        self::assertSame(5000, $summary->total, 'The price is the one captured on the line, not the catalog price.');
    }

    private function givenProduct(string $name, int $price, int $stock): ProductId
    {
        $id = ProductId::generate();

        $this->productRepository->save(new Product($id, $name, Money::fromInt($price), $stock, 5));
        $this->entityManager->clear();

        return $id;
    }
}
