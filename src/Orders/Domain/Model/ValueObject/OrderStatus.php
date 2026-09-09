<?php

declare(strict_types=1);

namespace App\Orders\Domain\Model\ValueObject;

enum OrderStatus: string
{
    case PLACED = 'placed';
    case PAID = 'paid';
    case CANCELLED = 'cancelled';
}
