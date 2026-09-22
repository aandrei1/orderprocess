<?php

declare(strict_types=1);

namespace App\Orders\Application\Query;

final class ListProducts
{
    public const int MAX_LIMIT = 200;

    private readonly int $limit;

    public function __construct(int $limit = 50)
    {
        $this->limit = min(self::MAX_LIMIT, max(1, $limit));
    }

    /** @return positive-int */
    public function limit(): int
    {
        return $this->limit;
    }
}
