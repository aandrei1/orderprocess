<?php

declare(strict_types=1);

namespace App\Orders\Domain\Exception;

final class PaymentFailed extends \DomainException
{
    public static function refused(): self
    {
        return new self('Payment failed.');
    }
}
