<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Persistence\Type;

use App\Orders\Domain\Model\ValueObject\ProductId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

final class ProductIdType extends Type
{
    public const string NAME = 'product_id';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?ProductId
    {
        if (null === $value) {
            return null;
        }

        return ProductId::fromString((string) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof ProductId) {
            throw new ConversionException(sprintf('Expected %s, got %s', ProductId::class, get_debug_type($value)));
        }

        return $value->toString();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
