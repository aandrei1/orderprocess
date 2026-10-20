<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Logging;

use Ramsey\Uuid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

final class CorrelationContext implements ResetInterface
{
    private ?string $id = null;
    private string $source = 'unknown';

    public function start(string $source, ?string $id = null): void
    {
        $this->source = $source;
        $this->id = $id ?? Uuid::uuid4()->toString();
    }

    public function id(): string
    {
        return $this->id ??= Uuid::uuid4()->toString();
    }

    public function source(): string
    {
        return $this->source;
    }

    public function reset(): void
    {
        $this->id = null;
        $this->source = 'unknown';
    }
}
