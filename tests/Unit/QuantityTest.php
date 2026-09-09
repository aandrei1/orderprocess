<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Orders\Domain\Model\ValueObject\Quantity;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    public function testFromIntCreatesValidQuantity(): void
    {
        $quantity = Quantity::fromInt(5);

        self::assertSame(5, $quantity->toInt());
    }

    public function testZeroThrowsDomainException(): void
    {
        $this->expectException(\DomainException::class);

        Quantity::fromInt(0);
    }

    public function testNegativeThrowsDomainException(): void
    {
        $this->expectException(\DomainException::class);

        Quantity::fromInt(-3);
    }

    public function testAdd(): void
    {
        $result = Quantity::fromInt(2)->add(Quantity::fromInt(3));

        self::assertSame(5, $result->toInt());
    }

    public function testSubtract(): void
    {
        $result = Quantity::fromInt(5)->subtract(Quantity::fromInt(2));

        self::assertSame(3, $result->toInt());
    }

    public function testEquals(): void
    {
        self::assertTrue(Quantity::fromInt(4)->equals(Quantity::fromInt(4)));
        self::assertFalse(Quantity::fromInt(4)->equals(Quantity::fromInt(5)));
    }
}
