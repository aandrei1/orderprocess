<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Orders\Domain\Model\Product;
use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\ProductId;
use App\Orders\Domain\Port\ProductRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the HTTP layer through the kernel: routing, payload validation, the
 * status codes the frontend branches on, and the JSON shape declared in
 * frontend/src/api.ts. A contract change here breaks the UI silently, so it is
 * asserted rather than assumed.
 */
final class OrderApiTest extends KernelTestCase
{
    private const string CUSTOMER_ID = '44444444-4444-4444-8444-444444444444';

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private ProductRepository $productRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->productRepository = $container->get(ProductRepository::class);

        $this->connection->executeStatement('TRUNCATE order_items, orders, products, outbox');
        $this->entityManager->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->entityManager->close();
    }

    public function testProductListingOffersOnlyWhatIsInStock(): void
    {
        $available = $this->givenProduct('Green tea', price: 1000, stock: 12);
        $this->givenProduct('Sold out', price: 900, stock: 0);

        $body = $this->json(Request::create('/api/products'), Response::HTTP_OK);

        self::assertCount(1, $body['products']);
        self::assertSame($available->toString(), $body['products'][0]['id']);
        self::assertSame('Green tea', $body['products'][0]['name']);
        self::assertSame(1000, $body['products'][0]['price']);
        self::assertSame(12, $body['products'][0]['stockQuantity']);
    }

    public function testPlacingAnOrderReturnsTheFinalStatus(): void
    {
        $product = $this->givenProduct('Dark chocolate', price: 4686, stock: 50);

        $body = $this->json(
            $this->postOrder([['productId' => $product->toString(), 'quantity' => 3]]),
            Response::HTTP_CREATED,
        );

        self::assertSame('paid', $body['status'], 'The UI shows this straight away — no polling.');
        self::assertSame(self::CUSTOMER_ID, $body['customerId']);
        self::assertSame(3 * 4686, $body['total']);
        self::assertSame(Money::CURRENCY_RON, $body['currency']);
        self::assertNotNull($body['paidAt']);
        self::assertCount(1, $body['items']);
        self::assertSame('Dark chocolate', $body['items'][0]['productName']);

        // The order really is readable afterwards, by the id just handed out.
        $fetched = $this->json(Request::create('/api/orders/' . $body['id']), Response::HTTP_OK);
        self::assertSame($body['id'], $fetched['id']);
    }

    public function testInsufficientStockIsRejectedAsAConflict(): void
    {
        $product = $this->givenProduct('Raisins', price: 2000, stock: 2);

        $body = $this->json(
            $this->postOrder([['productId' => $product->toString(), 'quantity' => 5]]),
            Response::HTTP_CONFLICT,
        );

        self::assertStringContainsString('Insufficient stock', $body['error']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM orders'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT stock_quantity FROM products WHERE id = ?', [$product->toString()]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}>
     */
    public static function malformedPayloads(): iterable
    {
        yield 'not json' => ['{nope'];
        yield 'missing customerId' => [['items' => [['productId' => 'x', 'quantity' => 1]]]];
        yield 'customerId not a uuid' => [['customerId' => 'nope', 'items' => [['productId' => 'x', 'quantity' => 1]]]];
        yield 'no items' => [['customerId' => self::CUSTOMER_ID, 'items' => []]];
        yield 'quantity zero' => [['customerId' => self::CUSTOMER_ID, 'items' => [['productId' => 'x', 'quantity' => 0]]]];
        yield 'quantity not an int' => [['customerId' => self::CUSTOMER_ID, 'items' => [['productId' => 'x', 'quantity' => '2']]]];
        yield 'item missing productId' => [['customerId' => self::CUSTOMER_ID, 'items' => [['quantity' => 1]]]];
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedPayloads')]
    public function testMalformedPayloadsAreRejectedBeforeReachingTheDomain(array|string $payload): void
    {
        $request = Request::create(
            '/api/orders',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: \is_string($payload) ? $payload : json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        $body = $this->json($request, Response::HTTP_BAD_REQUEST);

        self::assertArrayHasKey('error', $body);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM orders'));
    }

    public function testUnknownOrderIsNotFound(): void
    {
        $this->json(
            Request::create('/api/orders/55555555-5555-4555-8555-555555555555'),
            Response::HTTP_NOT_FOUND,
        );
    }

    public function testListingIsPagedAndCapped(): void
    {
        $product = $this->givenProduct('Green tea', price: 1000, stock: 50);

        for ($i = 0; $i < 3; ++$i) {
            $this->json(
                $this->postOrder([['productId' => $product->toString(), 'quantity' => 1]]),
                Response::HTTP_CREATED,
            );
        }

        $body = $this->json(Request::create('/api/orders?page=1&perPage=2'), Response::HTTP_OK);
        self::assertSame(3, $body['total']);
        self::assertCount(2, $body['orders']);
        self::assertSame(2, $body['perPage']);

        // Hostile paging parameters are clamped, never passed through to SQL.
        $clamped = $this->json(Request::create('/api/orders?page=0&perPage=99999'), Response::HTTP_OK);
        self::assertSame(1, $clamped['page']);
        self::assertSame(100, $clamped['perPage']);
    }

    /**
     * @param list<array{productId: string, quantity: int}> $items
     */
    private function postOrder(array $items): Request
    {
        return Request::create(
            '/api/orders',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['customerId' => self::CUSTOMER_ID, 'items' => $items], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Request $request, int $expectedStatus): array
    {
        $response = self::$kernel->handle($request);

        self::assertSame($expectedStatus, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function givenProduct(string $name, int $price, int $stock): ProductId
    {
        $id = ProductId::generate();

        $this->productRepository->save(new Product($id, $name, Money::fromInt($price), $stock, 5));
        $this->entityManager->clear();

        return $id;
    }
}
