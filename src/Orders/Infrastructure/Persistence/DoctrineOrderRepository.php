<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Persistence;

use App\Orders\Domain\Model\Order;
use App\Orders\Domain\Model\ValueObject\OrderId;
use App\Orders\Domain\Port\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineOrderRepository implements OrderRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function save(Order $order): void
    {
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }

    public function findById(OrderId $id): ?Order
    {
        // See DoctrineProductRepository: the identifier is passed as a value
        // object, the order_id DBAL type does the conversion.
        return $this->entityManager->find(Order::class, $id);
    }
}
