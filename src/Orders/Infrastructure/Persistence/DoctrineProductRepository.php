<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Persistence;

use App\Orders\Domain\Model\Product;
use App\Orders\Domain\Model\ValueObject\ProductId;
use App\Orders\Domain\Port\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineProductRepository implements ProductRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function save(Product $product): void
    {
        $this->entityManager->persist($product);
        $this->entityManager->flush();
    }

    public function findById(ProductId $id): ?Product
    {
        // The identifier is passed as a value object: the product_id DBAL type
        // does the conversion to string. With $id->toString() here, ProductIdType
        // would already receive a string and throw ConversionException.
        return $this->entityManager->find(Product::class, $id);
    }
}
