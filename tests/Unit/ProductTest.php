<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Orders\Domain\Event\ProductOutOfStock;
use App\Orders\Domain\Event\StockBelowThreshold;
use App\Orders\Domain\Model\Product;
use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\ProductId;
use App\Orders\Domain\Model\ValueObject\Quantity;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    private function createProduct(int $stock = 10, int $minThreshold = 3): Product
    {
        return new Product(
            ProductId::fromString('11111111-1111-1111-1111-111111111111'),
            'Laptop',
            Money::fromInt(5000),
            $stock,
            $minThreshold,
        );
    }

    public function testDecrementStockReducesQuantity(): void
    {
        $product = $this->createProduct(10, 3);
        $product->decrementStock(Quantity::fromInt(4), new \DateTimeImmutable('2026-01-01 10:00:00'));

        self::assertSame(6, $product->stockQuantity());
    }

    public function testDecrementStockBelowThresholdEmitsEvent(): void
    {
        $product = $this->createProduct(10, 3);
        $product->decrementStock(Quantity::fromInt(8), new \DateTimeImmutable('2026-01-01 10:00:00'));

        self::assertSame(2, $product->stockQuantity());

        $events = $product->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(StockBelowThreshold::class, $events[0]);
    }

    public function testDecrementStockToZeroEmitsOutOfStockEvent(): void
    {
        $product = $this->createProduct(5, 3);
        $product->decrementStock(Quantity::fromInt(5), new \DateTimeImmutable('2026-01-01 10:00:00'));

        self::assertSame(0, $product->stockQuantity());

        $events = $product->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ProductOutOfStock::class, $events[0]);
    }

    public function testDecrementStockInsufficientThrows(): void
    {
        $product = $this->createProduct(5, 3);

        $this->expectException(\DomainException::class);

        $product->decrementStock(Quantity::fromInt(6), new \DateTimeImmutable('2026-01-01 10:00:00'));
    }

    public function testRestockIncreasesQuantity(): void
    {
        $product = $this->createProduct(5, 3);
        $product->restock(Quantity::fromInt(10));

        self::assertSame(15, $product->stockQuantity());
    }

    public function testIsBelowThreshold(): void
    {
        $product = $this->createProduct(3, 3);
        self::assertTrue($product->isBelowThreshold());

        $product2 = $this->createProduct(4, 3);
        self::assertFalse($product2->isBelowThreshold());
    }

    public function testNegativeStockThrows(): void
    {
        $this->expectException(\DomainException::class);

        new Product(
            ProductId::fromString('11111111-1111-1111-1111-111111111111'),
            'Laptop',
            Money::fromInt(5000),
            -1,
            3,
        );
    }
}
