<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow owners to withdraw unpublished drafts without fabricating proof approval';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resale_listings DROP CONSTRAINT resale_listings_check');
        $this->addSql("ALTER TABLE resale_listings ADD CONSTRAINT ck_listing_published_proof CHECK(state NOT IN ('published','sold') OR (entitlement_id IS NOT NULL AND proof_id IS NOT NULL AND approved_proof_version IS NOT NULL AND published_at IS NOT NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resale_listings DROP CONSTRAINT ck_listing_published_proof');
        $this->addSql("ALTER TABLE resale_listings ADD CONSTRAINT resale_listings_check CHECK(state='draft' OR (entitlement_id IS NOT NULL AND proof_id IS NOT NULL AND approved_proof_version IS NOT NULL AND published_at IS NOT NULL))");
    }
}
