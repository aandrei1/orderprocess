<?php

declare(strict_types=1);

namespace App\Orders\UI\Controller;

use App\Orders\Application\Command\PlaceOrder;
use App\Orders\Application\Command\PlaceOrderHandler;
use App\Orders\Application\Query\FindOrder;
use App\Orders\Application\Query\ListOrders;
use App\Orders\Application\Query\View\OrderPage;
use App\Orders\Application\Query\View\OrderSummary;
use App\Orders\Domain\Model\ValueObject\CustomerId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/orders')]
final class OrderController extends AbstractController
{
    use HandleTrait;

    public function __construct(
        /**
         * Injected directly, not dispatched on command.bus: `PlaceOrder` is
         * routed `async`, and the UI needs the resulting status in the same
         * request. Same call path as `UI/Command/PlaceOrderCommand`, so the
         * handler's own transaction boundary still applies.
         */
        private readonly PlaceOrderHandler $placeOrder,
        MessageBusInterface $queryBus,
    ) {
        $this->messageBus = $queryBus;
    }

    #[Route('', methods: ['POST'])]
    public function place(Request $request): JsonResponse
    {
        try {
            $command = $this->toCommand($request->getContent());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        try {
            $orderId = ($this->placeOrder)($command);
        } catch (\DomainException $e) {
            // Insufficient stock, unknown product, refused payment: the request
            // was well formed, the domain refused it.
            return $this->error($e->getMessage(), Response::HTTP_CONFLICT);
        }

        /** @var OrderSummary|null $summary */
        $summary = $this->handle(new FindOrder($orderId->toString()));

        if (null === $summary) {
            return $this->error('Order was placed but could not be read back.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse($this->toArray($summary), Response::HTTP_CREATED);
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var OrderPage $page */
        $page = $this->handle(new ListOrders(
            $request->query->getInt('page', 1),
            $request->query->getInt('perPage', 20),
        ));

        return new JsonResponse([
            'orders' => array_map($this->toArray(...), $page->orders),
            'total' => $page->total,
            'page' => $page->page,
            'perPage' => $page->perPage,
        ]);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        /** @var OrderSummary|null $summary */
        $summary = $this->handle(new FindOrder($id));

        if (null === $summary) {
            return $this->error('Order not found.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->toArray($summary));
    }

    /**
     * @throws \InvalidArgumentException when the payload is not a valid order request
     */
    private function toCommand(string $body): PlaceOrder
    {
        try {
            $payload = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(sprintf('Invalid JSON body: %s', $e->getMessage()));
        }

        if (!\is_array($payload)) {
            throw new \InvalidArgumentException('Body must be a JSON object.');
        }

        if (!isset($payload['customerId']) || !\is_string($payload['customerId'])) {
            throw new \InvalidArgumentException('Field "customerId" is required and must be a string.');
        }

        try {
            $customerId = CustomerId::fromString($payload['customerId']);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('Field "customerId" must be a valid UUID.');
        }

        if (!isset($payload['items']) || !\is_array($payload['items']) || [] === $payload['items']) {
            throw new \InvalidArgumentException('Field "items" is required and must be a non-empty array.');
        }

        $items = [];

        foreach (array_values($payload['items']) as $index => $item) {
            if (!\is_array($item) || !isset($item['productId']) || !\is_string($item['productId'])) {
                throw new \InvalidArgumentException(sprintf('Item #%d: "productId" is required and must be a string.', $index));
            }

            if (!isset($item['quantity']) || !\is_int($item['quantity']) || $item['quantity'] < 1) {
                throw new \InvalidArgumentException(sprintf('Item #%d: "quantity" is required and must be a positive integer.', $index));
            }

            $items[] = ['productId' => $item['productId'], 'quantity' => $item['quantity']];
        }

        return new PlaceOrder($customerId, $items);
    }

    /**
     * The HTTP contract lives here, in the UI layer: view models stay free of
     * any opinion about how they are serialised.
     *
     * @return array<string, mixed>
     */
    private function toArray(OrderSummary $order): array
    {
        return [
            'id' => $order->id,
            'customerId' => $order->customerId,
            'status' => $order->status,
            'total' => $order->total,
            'currency' => $order->currency,
            'placedAt' => $order->placedAt->format(\DATE_ATOM),
            'paidAt' => $order->paidAt?->format(\DATE_ATOM),
            'items' => array_map(
                static fn ($item): array => [
                    'productId' => $item->productId,
                    'productName' => $item->productName,
                    'quantity' => $item->quantity,
                    'unitPrice' => $item->unitPrice,
                    'subtotal' => $item->subtotal,
                ],
                $order->items,
            ),
        ];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
