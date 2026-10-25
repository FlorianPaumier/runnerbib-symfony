<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Freeze completed eligibility import outcomes and provenance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE FUNCTION marketplace_freeze_completed_import() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.state='completed' THEN
  IF TG_OP='DELETE' THEN
   RAISE EXCEPTION 'Completed import cannot be deleted';
  END IF;
  IF NEW IS DISTINCT FROM OLD THEN
   RAISE EXCEPTION 'Completed import cannot be rewritten';
  END IF;
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END;
$$
SQL);
        $this->addSql('CREATE TRIGGER freeze_completed_import BEFORE UPDATE OR DELETE ON eligibility_imports FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_completed_import()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER freeze_completed_import ON eligibility_imports');
        $this->addSql('DROP FUNCTION marketplace_freeze_completed_import()');
    }
}
