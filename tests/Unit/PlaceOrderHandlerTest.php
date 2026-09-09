<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Orders\Application\Command\PlaceOrder;
use App\Orders\Application\Command\PlaceOrderHandler;
use App\Orders\Application\Port\DomainEventDispatcher;
use App\Orders\Domain\Event\OrderPaid;
use App\Orders\Domain\Event\OrderPlaced;
use App\Orders\Domain\Event\StockBelowThreshold;
use App\Orders\Domain\Model\Product;
use App\Orders\Domain\Model\ValueObject\CustomerId;
use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\ProductId;
use App\Orders\Domain\Port\OrderRepository;
use App\Orders\Domain\Port\PaymentGateway;
use App\Orders\Domain\Port\ProductRepository;
use PHPUnit\Framework\TestCase;

final class PlaceOrderHandlerTest extends TestCase
{
    private const string PRODUCT_ID = '11111111-1111-1111-1111-111111111111';

    private function createProduct(int $stock = 10, int $minThreshold = 3): Product
    {
        return new Product(
            ProductId::fromString(self::PRODUCT_ID),
            'Laptop',
            Money::fromInt(5000),
            $stock,
            $minThreshold,
        );
    }

    private function createHandler(
        ProductRepository $productRepository,
        PaymentGateway $paymentGateway,
        array &$dispatchedEvents,
    ): PlaceOrderHandler {
        $orderRepository = $this->createMock(OrderRepository::class);
        $orderRepository->method('save');

        $dispatcher = $this->createMock(DomainEventDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            static function ($event) use (&$dispatchedEvents): void {
                $dispatchedEvents[] = $event;
            },
        );

        return new PlaceOrderHandler(
            $orderRepository,
            $productRepository,
            $paymentGateway,
            $dispatcher,
        );
    }

    public function testPlaceOrderSuccess(): void
    {
        $product = $this->createProduct(10, 3);

        $productRepository = $this->createMock(ProductRepository::class);
        $productRepository->method('findById')->willReturn($product);
        $productRepository->expects(self::once())->method('save')->with($product);

        $paymentGateway = $this->createMock(PaymentGateway::class);
        $paymentGateway->method('charge')->willReturn(true);

        $dispatchedEvents = [];
        $handler = $this->createHandler($productRepository, $paymentGateway, $dispatchedEvents);

        $orderId = $handler(new PlaceOrder(
            CustomerId::fromString('33333333-3333-3333-3333-333333333333'),
            [['productId' => self::PRODUCT_ID, 'quantity' => 2]],
        ));

        self::assertNotNull($orderId);
        self::assertSame(8, $product->stockQuantity());

        $eventClasses = array_map(static fn ($e) => $e::class, $dispatchedEvents);
        self::assertContains(OrderPlaced::class, $eventClasses);
        self::assertContains(OrderPaid::class, $eventClasses);
    }

    public function testPlaceOrderEmitsStockBelowThreshold(): void
    {
        $product = $this->createProduct(10, 3);

        $productRepository = $this->createMock(ProductRepository::class);
        $productRepository->method('findById')->willReturn($product);

        $paymentGateway = $this->createMock(PaymentGateway::class);
        $paymentGateway->method('charge')->willReturn(true);

        $dispatchedEvents = [];
        $handler = $this->createHandler($productRepository, $paymentGateway, $dispatchedEvents);

        $handler(new PlaceOrder(
            CustomerId::fromString('33333333-3333-3333-3333-333333333333'),
            [['productId' => self::PRODUCT_ID, 'quantity' => 8]],
        ));

        $eventClasses = array_map(static fn ($e) => $e::class, $dispatchedEvents);
        self::assertContains(StockBelowThreshold::class, $eventClasses);
    }

    public function testPlaceOrderInsufficientStockThrows(): void
    {
        $product = $this->createProduct(5, 3);

        $productRepository = $this->createMock(ProductRepository::class);
        $productRepository->method('findById')->willReturn($product);

        $paymentGateway = $this->createMock(PaymentGateway::class);
        $paymentGateway->expects(self::never())->method('charge');

        $dispatchedEvents = [];
        $handler = $this->createHandler($productRepository, $paymentGateway, $dispatchedEvents);

        $this->expectException(\DomainException::class);

        $handler(new PlaceOrder(
            CustomerId::fromString('33333333-3333-3333-3333-333333333333'),
            [['productId' => self::PRODUCT_ID, 'quantity' => 6]],
        ));
    }

    public function testPlaceOrderProductNotFoundThrows(): void
    {
        $productRepository = $this->createMock(ProductRepository::class);
        $productRepository->method('findById')->willReturn(null);

        $paymentGateway = $this->createMock(PaymentGateway::class);
        $paymentGateway->expects(self::never())->method('charge');

        $dispatchedEvents = [];
        $handler = $this->createHandler($productRepository, $paymentGateway, $dispatchedEvents);

        $this->expectException(\DomainException::class);

        $handler(new PlaceOrder(
            CustomerId::fromString('33333333-3333-3333-3333-333333333333'),
            [['productId' => self::PRODUCT_ID, 'quantity' => 2]],
        ));
    }

    public function testPlaceOrderPaymentFailedThrows(): void
    {
        $product = $this->createProduct(10, 3);

        $productRepository = $this->createMock(ProductRepository::class);
        $productRepository->method('findById')->willReturn($product);

        $paymentGateway = $this->createMock(PaymentGateway::class);
        $paymentGateway->method('charge')->willReturn(false);

        $dispatchedEvents = [];
        $handler = $this->createHandler($productRepository, $paymentGateway, $dispatchedEvents);

        $this->expectException(\DomainException::class);

        $handler(new PlaceOrder(
            CustomerId::fromString('33333333-3333-3333-3333-333333333333'),
            [['productId' => self::PRODUCT_ID, 'quantity' => 2]],
        ));
    }
}
