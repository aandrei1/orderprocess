<?php

declare(strict_types=1);

namespace App\Orders\UI\Controller;

use App\Orders\Application\Query\ListProducts;
use App\Orders\Application\Query\View\ProductSummary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/products')]
final class ProductController extends AbstractController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var list<ProductSummary> $products */
        $products = $this->handle(new ListProducts($request->query->getInt('limit', 50)));

        return new JsonResponse([
            'products' => array_map(
                static fn (ProductSummary $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'currency' => $product->currency,
                    'stockQuantity' => $product->stockQuantity,
                ],
                $products,
            ),
        ]);
    }
}
