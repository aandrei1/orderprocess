<?php

declare(strict_types=1);

namespace App\Orders\UI\Command;

use App\Orders\Application\Command\PlaceOrder;
use App\Orders\Application\Command\PlaceOrderHandler;
use App\Orders\Domain\Model\ValueObject\CustomerId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:place-order', description: 'Places an order for a customer.')]
final class PlaceOrderCommand extends Command
{
    public function __construct(private readonly PlaceOrderHandler $handler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('customerId', InputArgument::REQUIRED, 'The customer ID')
            ->addArgument('items', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Items: productId:quantity (e.g. prod-1:2 prod-2:1)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $items = [];

        foreach ($input->getArgument('items') as $raw) {
            [$productId, $quantity] = explode(':', $raw, 2);
            $items[] = ['productId' => $productId, 'quantity' => (int) $quantity];
        }

        $orderId = ($this->handler)(new PlaceOrder(
            CustomerId::fromString((string) $input->getArgument('customerId')),
            $items,
        ));

        $output->writeln(sprintf('Order %s has been placed.', $orderId->toString()));

        return Command::SUCCESS;
    }
}
