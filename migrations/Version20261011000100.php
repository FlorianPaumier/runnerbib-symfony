<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index seller inventory and listing history by seller and entitlement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX ix_listing_seller_entitlement ON resale_listings(seller_id,entitlement_id,id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX ix_listing_seller_entitlement');
    }
}
