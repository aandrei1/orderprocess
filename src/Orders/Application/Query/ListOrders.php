<?php

declare(strict_types=1);

namespace App\Orders\Application\Query;

final class ListOrders
{
    public const int MAX_PER_PAGE = 100;

    private readonly int $page;

    private readonly int $perPage;

    public function __construct(int $page = 1, int $perPage = 20)
    {
        $this->page = max(1, $page);
        $this->perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
    }

    /** @return positive-int */
    public function page(): int
    {
        return $this->page;
    }

    /** @return positive-int */
    public function perPage(): int
    {
        return $this->perPage;
    }
}
