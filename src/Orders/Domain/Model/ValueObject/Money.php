<?php

declare(strict_types=1);

namespace App\Orders\Domain\Model\ValueObject;

final class Money
{
    public const string CURRENCY_RON = 'RON';

    private function __construct(
        private readonly int $amount,
        private readonly string $currency = self::CURRENCY_RON,
    ) {
        if ($amount < 0) {
            throw new \DomainException('Money amount cannot be negative.');
        }
    }

    public static function fromInt(int $amount, string $currency = self::CURRENCY_RON): self
    {
        return new self($amount, $currency);
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new \DomainException('Money multiplier cannot be negative.');
        }

        return new self($this->amount * $factor, $this->currency);
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount <=> $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \DomainException(sprintf('Cannot operate on Money with different currencies: %s vs %s.', $this->currency, $other->currency));
        }
    }
}
