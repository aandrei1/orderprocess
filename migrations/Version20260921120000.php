<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index orders by placed_at for the paginated order listing.';
    }

    public function up(Schema $schema): void
    {
        // GET /api/orders pages by "ORDER BY placed_at DESC, id DESC"; the
        // index matches that order so the listing never sorts the whole table.
        $this->addSql('CREATE INDEX idx_orders_placed_at ON orders (placed_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_orders_placed_at');
    }
}
