<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

require __DIR__ . '/../vendor/autoload.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();

/** @var ContainerInterface $container */
$container = $kernel->getContainer();

return $container->get(EntityManagerInterface::class);
