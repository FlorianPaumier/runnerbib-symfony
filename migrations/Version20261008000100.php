<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Install the consolidated 40-table marketplace schema and integrity constraints';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'PostgreSQL is required.');
        $sql = file_get_contents(__DIR__.'/sql/20261008000100-up.sql');
        if (false === $sql) {
            throw new \RuntimeException('Migration SQL is missing.');
        }
        foreach (explode("\n-- statement\n", $sql) as $statement) {
            if ('' !== trim($statement)) {
                $this->addSql(trim($statement));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $sql = file_get_contents(__DIR__.'/sql/20261008000100-down.sql');
        if (false === $sql) {
            throw new \RuntimeException('Rollback SQL is missing.');
        }
        foreach (explode("\n-- statement\n", $sql) as $statement) {
            if ('' !== trim($statement)) {
                $this->addSql(trim($statement));
            }
        }
    }
}
