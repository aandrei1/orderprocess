<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Messaging;

use App\Orders\Domain\Model\ValueObject\Money;
use App\Orders\Domain\Port\PaymentGateway;

final class MockPaymentGateway implements PaymentGateway
{
    public function charge(Money $amount): bool
    {
        // Mock: acceptă orice plată.
        return true;
    }
}
