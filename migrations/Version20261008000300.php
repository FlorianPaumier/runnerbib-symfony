<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist private-channel delivery outcome metadata without changing outbox intent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE outbox_messages ADD COLUMN delivery_state jsonb NOT NULL DEFAULT '{}' CHECK(jsonb_typeof(delivery_state)='object')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE outbox_messages DROP COLUMN delivery_state');
    }
}
