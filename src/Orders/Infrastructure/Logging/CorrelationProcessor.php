<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

#[AsMonologProcessor(channel: 'orders')]
final class CorrelationProcessor
{
    public function __construct(private readonly CorrelationContext $context)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [
            ...$record->extra,
            'correlation_id' => $this->context->id(),
            'source' => $this->context->source(),
        ]);
    }
}
