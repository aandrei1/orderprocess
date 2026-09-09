<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Orders\Domain\Model\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testFromIntCreatesValidMoney(): void
    {
        $money = Money::fromInt(1000);

        self::assertSame(1000, $money->amount());
        self::assertSame('RON', $money->currency());
    }

    public function testNegativeThrowsDomainException(): void
    {
        $this->expectException(\DomainException::class);

        Money::fromInt(-1);
    }

    public function testAdd(): void
    {
        $result = Money::fromInt(1000)->add(Money::fromInt(500));

        self::assertSame(1500, $result->amount());
    }

    public function testAddDifferentCurrencyThrows(): void
    {
        $this->expectException(\DomainException::class);

        Money::fromInt(1000)->add(Money::fromInt(500, 'EUR'));
    }

    public function testMultiply(): void
    {
        $result = Money::fromInt(250)->multiply(4);

        self::assertSame(1000, $result->amount());
    }

    public function testMultiplyNegativeThrows(): void
    {
        $this->expectException(\DomainException::class);

        Money::fromInt(250)->multiply(-1);
    }

    public function testCompareTo(): void
    {
        self::assertSame(-1, Money::fromInt(100)->compareTo(Money::fromInt(200)));
        self::assertSame(0, Money::fromInt(100)->compareTo(Money::fromInt(100)));
        self::assertSame(1, Money::fromInt(200)->compareTo(Money::fromInt(100)));
    }

    public function testEquals(): void
    {
        self::assertTrue(Money::fromInt(100)->equals(Money::fromInt(100)));
        self::assertFalse(Money::fromInt(100)->equals(Money::fromInt(101)));
    }
}
