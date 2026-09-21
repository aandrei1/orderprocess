<?php

declare(strict_types=1);

namespace App\Orders\Domain\Model\ValueObject;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class ProductId implements \Stringable
{
    private function __construct(private readonly UuidInterface $uuid)
    {
    }

    public static function generate(): self
    {
        return new self(Uuid::uuid4());
    }

    public static function fromString(string $value): self
    {
        return new self(Uuid::fromString($value));
    }

    public function toString(): string
    {
        return $this->uuid->toString();
    }

    /** Doctrine builds the identity map key via a cast to string. */
    public function __toString(): string
    {
        return $this->toString();
    }

    public function equals(self $other): bool
    {
        return $this->uuid->equals($other->uuid);
    }
}
