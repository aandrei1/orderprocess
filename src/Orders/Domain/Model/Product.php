<?php

declare(strict_types=1);

namespace App\Orders\Domain\Model;

use App\Orders\Domain\Event\DomainEvent;
use App\Orders\Domain\Event\ProductOutOfStock;
use App\Orders\Domain\Event\StockBelowThreshold;
use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Model\ValueObject\ProductId;
use App\Orders\Domain\Model\ValueObject\Quantity;

final class Product
{
    /** @var list<DomainEvent> */
    private array $domainEvents = [];

    /** @var int Managed by Doctrine for optimistic locking. */
    private int $version = 1;

    public function __construct(
        private readonly ProductId $id,
        private readonly string $name,
        private readonly Money $price,
        private int $stockQuantity,
        private readonly int $minThreshold,
    ) {
        if ($stockQuantity < 0) {
            throw new \DomainException('Stock quantity cannot be negative.');
        }

        if ($minThreshold < 0) {
            throw new \DomainException('Min threshold cannot be negative.');
        }
    }

    public function decrementStock(Quantity $quantity, \DateTimeImmutable $occurredAt): void
    {
        if ($quantity->toInt() > $this->stockQuantity) {
            throw new \DomainException(sprintf('Insufficient stock for product %s: requested %d, available %d.', $this->id->toString(), $quantity->toInt(), $this->stockQuantity));
        }

        $this->stockQuantity -= $quantity->toInt();

        if (0 === $this->stockQuantity) {
            $this->recordEvent(new ProductOutOfStock($this->id, $occurredAt));
        } elseif ($this->stockQuantity <= $this->minThreshold) {
            $this->recordEvent(new StockBelowThreshold(
                $this->id,
                $this->stockQuantity,
                $this->minThreshold,
                $occurredAt,
            ));
        }
    }

    public function restock(Quantity $quantity): void
    {
        $this->stockQuantity += $quantity->toInt();
    }

    public function isBelowThreshold(): bool
    {
        return $this->stockQuantity <= $this->minThreshold;
    }

    public function id(): ProductId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function stockQuantity(): int
    {
        return $this->stockQuantity;
    }

    public function minThreshold(): int
    {
        return $this->minThreshold;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    private function recordEvent(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }
}
