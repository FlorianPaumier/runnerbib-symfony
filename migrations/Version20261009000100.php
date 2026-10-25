<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separate optional in-app communication preference from email and explicit race subscriptions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts ADD COLUMN optional_in_app_enabled boolean NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts DROP COLUMN optional_in_app_enabled');
    }
}
