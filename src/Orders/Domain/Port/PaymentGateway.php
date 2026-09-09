<?php

declare(strict_types=1);

namespace App\Orders\Domain\Port;

use App\Orders\Domain\Model\ValueObject\Money;

interface PaymentGateway
{
    public function charge(Money $amount): bool;
}
