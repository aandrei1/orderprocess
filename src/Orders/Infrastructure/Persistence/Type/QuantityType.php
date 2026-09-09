<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Persistence\Type;

use App\Orders\Domain\Model\ValueObject\Quantity;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

final class QuantityType extends Type
{
    public const string NAME = 'quantity';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Quantity
    {
        if (null === $value) {
            return null;
        }

        return Quantity::fromInt((int) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof Quantity) {
            throw new ConversionException(sprintf('Expected %s, got %s', Quantity::class, get_debug_type($value)));
        }

        return $value->toInt();
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
