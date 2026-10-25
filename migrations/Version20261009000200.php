<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal seller recipient-account creation before provider calls with redactable private identity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE seller_onboarding_operations (
    id uuid PRIMARY KEY,
    seller_id uuid NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
    provider text NOT NULL,
    provider_scope text NOT NULL,
    idempotency_key text NOT NULL,
    parameters jsonb NOT NULL CHECK(jsonb_typeof(parameters)='object'),
    parameters_hash text NOT NULL,
    private_payload jsonb CHECK(private_payload IS NULL OR jsonb_typeof(private_payload)='object'),
    private_redacted_at timestamptz,
    state text NOT NULL CHECK(state IN('prepared','submitted','unknown','succeeded','failed')),
    created_at timestamptz NOT NULL,
    submitted_at timestamptz,
    resolved_at timestamptz,
    provider_ref text,
    claimed_by uuid,
    lease_until timestamptz,
    fencing_generation bigint NOT NULL DEFAULT 0 CHECK(fencing_generation>=0),
    UNIQUE(seller_id,provider,provider_scope),
    UNIQUE(provider,provider_scope,idempotency_key),
    UNIQUE(provider,provider_scope,provider_ref),
    CHECK(state NOT IN('succeeded','failed') OR resolved_at IS NOT NULL),
    CHECK(state <> 'succeeded' OR provider_ref IS NOT NULL),
    CHECK(state NOT IN('submitted','unknown') OR submitted_at IS NOT NULL),
    CHECK((claimed_by IS NULL)=(lease_until IS NULL)),
    CHECK(private_redacted_at IS NULL OR private_payload IS NULL)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION freeze_seller_onboarding_intent() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF ROW(NEW.id,NEW.seller_id,NEW.provider,NEW.provider_scope,NEW.idempotency_key,NEW.parameters,NEW.parameters_hash,NEW.created_at)
       IS DISTINCT FROM ROW(OLD.id,OLD.seller_id,OLD.provider,OLD.provider_scope,OLD.idempotency_key,OLD.parameters,OLD.parameters_hash,OLD.created_at) THEN
        RAISE EXCEPTION 'Seller onboarding intent is immutable' USING ERRCODE='23514';
    END IF;
    IF OLD.private_redacted_at IS NOT NULL AND
       ROW(NEW.private_payload,NEW.private_redacted_at) IS DISTINCT FROM ROW(OLD.private_payload,OLD.private_redacted_at) THEN
        RAISE EXCEPTION 'Redaction is irreversible' USING ERRCODE='23514';
    END IF;
    IF OLD.private_payload IS DISTINCT FROM NEW.private_payload AND
       NOT(NEW.private_payload IS NULL AND NEW.private_redacted_at IS NOT NULL) THEN
        RAISE EXCEPTION 'Private identity can only be redacted' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END
$$
SQL);
        $this->addSql('CREATE TRIGGER seller_onboarding_intent_immutable BEFORE UPDATE ON seller_onboarding_operations FOR EACH ROW EXECUTE FUNCTION freeze_seller_onboarding_intent()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE seller_onboarding_operations');
        $this->addSql('DROP FUNCTION freeze_seller_onboarding_intent()');
    }
}
