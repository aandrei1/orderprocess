<?php

declare(strict_types=1);

namespace App\Orders\Infrastructure\Logging;

use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class CorrelationListener
{
    public const string HEADER = 'X-Correlation-Id';

    // The header is client-controlled and ends up in the logs: accept only a
    // short, plain token, never arbitrary text.
    private const string VALID_ID = '/^[A-Za-z0-9._-]{8,64}$/';

    public function __construct(private readonly CorrelationContext $context)
    {
    }

    #[AsEventListener(priority: 512)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $incoming = $event->getRequest()->headers->get(self::HEADER);

        $this->context->start('http', null !== $incoming && 1 === preg_match(self::VALID_ID, $incoming) ? $incoming : null);
    }

    #[AsEventListener]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->set(self::HEADER, $this->context->id());
        }
    }

    #[AsEventListener]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->context->start('cli');
    }
}
