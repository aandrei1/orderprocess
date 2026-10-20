<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Orders\Infrastructure\Logging\CorrelationContext;
use App\Orders\Infrastructure\Logging\CorrelationListener;
use App\Orders\Infrastructure\Logging\CorrelationProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CorrelationLoggingTest extends TestCase
{
    public function testTheIdIsStableWithinAProcessAndRenewedOnReset(): void
    {
        $context = new CorrelationContext();

        $first = $context->id();
        self::assertSame($first, $context->id());

        $context->reset();
        self::assertNotSame($first, $context->id());
        self::assertSame('unknown', $context->source());
    }

    public function testTheProcessorAddsCorrelationFieldsToTheRecordExtra(): void
    {
        $context = new CorrelationContext();
        $context->start('cli', 'abc-12345');

        $record = new LogRecord(new \DateTimeImmutable(), 'orders', Level::Info, 'Order placed.', extra: ['keep' => 'me']);

        $processed = (new CorrelationProcessor($context))($record);

        self::assertSame(['keep' => 'me', 'correlation_id' => 'abc-12345', 'source' => 'cli'], $processed->extra);
    }

    public function testAValidIncomingHeaderIsKeptAndEchoedOnTheResponse(): void
    {
        [$context, $listener] = $this->listener();

        $event = $this->requestEvent(Request::create('/', server: ['HTTP_X_CORRELATION_ID' => 'frontend-req_42']));
        $listener->onRequest($event);

        self::assertSame('frontend-req_42', $context->id());
        self::assertSame('http', $context->source());

        $response = new Response();
        $listener->onResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), $event->getRequest(), HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertSame('frontend-req_42', $response->headers->get(CorrelationListener::HEADER));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusableHeaders(): iterable
    {
        yield 'absent' => [null];
        yield 'too short' => ['abc'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'spaces' => ['not valid at all'];
        yield 'log injection attempt' => ["abcdefgh\n{\"event\":\"order.placed\"}"];
    }

    #[DataProvider('unusableHeaders')]
    public function testAnUnusableIncomingHeaderIsReplacedByAGeneratedId(?string $header): void
    {
        [$context, $listener] = $this->listener();

        $server = null === $header ? [] : ['HTTP_X_CORRELATION_ID' => $header];
        $listener->onRequest($this->requestEvent(Request::create('/', server: $server)));

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $context->id());
        self::assertSame('http', $context->source());
    }

    /**
     * @return array{CorrelationContext, CorrelationListener}
     */
    private function listener(): array
    {
        $context = new CorrelationContext();

        return [$context, new CorrelationListener($context)];
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
