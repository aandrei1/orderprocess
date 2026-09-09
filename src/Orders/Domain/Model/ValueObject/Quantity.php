<?php

declare(strict_types=1);

namespace App\Orders\Domain\Model\ValueObject;

final class Quantity
{
    private function __construct(private readonly int $value)
    {
        if ($value <= 0) {
            throw new \DomainException('Quantity must be a positive integer.');
        }
    }

    public static function fromInt(int $value): self
    {
        return new self($value);
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function add(self $other): self
    {
        return new self($this->value + $other->value);
    }

    public function subtract(self $other): self
    {
        return new self($this->value - $other->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
