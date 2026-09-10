<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Messaging;

use App\Orders\Application\Port\DomainEventDispatcher;
use App\Orders\Domain\Event\DomainEvent;
use App\Orders\Domain\Model\ValueObject\Money;
use Doctrine\DBAL\Connection;

final class OutboxDomainEventDispatcher implements DomainEventDispatcher
{
    private const string DATE_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function dispatch(DomainEvent $event): void
    {
        $this->connection->insert('outbox', [
            'id' => $this->generateId(),
            'type' => $event::class,
            'payload' => json_encode($this->serialize($event), JSON_THROW_ON_ERROR),
            'occurred_at' => $event->occurredAt()->format(self::DATE_FORMAT),
            'status' => 'pending',
        ]);
    }

    /**
     * Domain events carry all fields as private readonly constructor-promoted
     * properties, so get_object_vars() (public scope) sees nothing and the
     * payload would be "{}". Read the properties through reflection instead.
     *
     * @return array<string, mixed>
     */
    private function serialize(DomainEvent $event): array
    {
        $data = [];

        foreach (new \ReflectionObject($event)->getProperties() as $property) {
            $data[$property->getName()] = $this->normalize($property->getValue($event));
        }

        return $data;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->format(self::DATE_FORMAT);
        }

        if ($value instanceof Money) {
            return $value->amount();
        }

        if (is_object($value) && method_exists($value, 'toString')) {
            return $value->toString();
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        if (null === $value || is_scalar($value)) {
            return $value;
        }

        // Never silently encode an unknown object to "{}" (the original bug).
        throw new \LogicException(sprintf('Cannot serialize %s for the outbox payload.', get_debug_type($value)));
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
