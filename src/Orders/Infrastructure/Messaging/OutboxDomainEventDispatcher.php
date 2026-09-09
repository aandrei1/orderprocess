<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Messaging;

use App\Orders\Application\Port\DomainEventDispatcher;
use App\Orders\Domain\Event\DomainEvent;
use Doctrine\DBAL\Connection;

final class OutboxDomainEventDispatcher implements DomainEventDispatcher
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function dispatch(DomainEvent $event): void
    {
        $this->connection->insert('outbox', [
            'id' => $this->generateId(),
            'type' => $event::class,
            'payload' => json_encode($this->serialize($event), JSON_THROW_ON_ERROR),
            'occurred_at' => $event->occurredAt()->format('Y-m-d H:i:s.u'),
            'status' => 'pending',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(DomainEvent $event): array
    {
        $data = [];

        foreach (get_object_vars($event) as $key => $value) {
            $data[$key] = $this->normalize($value);
        }

        return $data;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->format('Y-m-d H:i:s.u');
        }

        if (is_object($value) && method_exists($value, 'toString')) {
            return $value->toString();
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        return $value;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
