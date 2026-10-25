<?php

declare(strict_types=1);

namespace App\Tests\Integration\Newsletter;

use App\Application\Newsletter\ManageNewsletter;
use App\Infrastructure\Newsletter\DbalNewsletterStore;
use App\Infrastructure\Newsletter\HmacNewsletterTokens;
use App\Infrastructure\Operations\DbalOutboxWriter;
use App\Infrastructure\Operations\DbalTransactionManager;
use App\Tests\Support\IsolatedWebTestCase;

final class NewsletterLifecycleTest extends IsolatedWebTestCase
{
    public function testSignupNormalizesEmailAndIsIndependentAndIdempotent(): void
    {
        $newsletter = $this->newsletter();
        $newsletter->subscribe('00000000-0000-0000-0000-000000000001', ' Runner@Example.TEST ', new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $newsletter->subscribe('00000000-0000-0000-0000-000000000002', 'runner@example.test', new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        self::assertEquals(1, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
        self::assertSame('runner@example.test', $this->database->fetchOne('SELECT email_normalized FROM newsletter_subscriptions'));
        self::assertTrue($this->database->fetchOne('SELECT subscribed FROM newsletter_subscriptions'));
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM accounts'));
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM race_waitlist_memberships'));
        self::assertEquals(1, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='newsletter.subscription_registered'"));
    }

    public function testPurposeSpecificUnsubscribeNeedsNoReasonAndIsIdempotent(): void
    {
        $newsletter = $this->newsletter();
        $newsletter->subscribe('00000000-0000-0000-0000-000000000001', 'runner@example.test', new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $token = $this->latestToken();
        self::assertTrue($newsletter->unsubscribe($token, null, new \DateTimeImmutable('2026-10-10T11:00:00Z')));
        self::assertFalse($newsletter->unsubscribe($token, 'Ignored repeated reason', new \DateTimeImmutable('2026-10-10T12:00:00Z')));
        self::assertFalse($this->database->fetchOne('SELECT subscribed FROM newsletter_subscriptions'));
        self::assertNull($this->database->fetchOne('SELECT quit_reason FROM newsletter_subscriptions'));
        self::assertSame('2026-10-10 11:00:00+00', $this->database->fetchOne('SELECT quit_at::text FROM newsletter_subscriptions'));
    }

    public function testRejoinPreservesTheRowClearsQuitFieldsAndRevokesOldAuthorityEvenAtTheSameInstant(): void
    {
        $newsletter = $this->newsletter();
        $now = new \DateTimeImmutable('2026-10-10T10:00:00Z');
        $newsletter->subscribe('00000000-0000-0000-0000-000000000001', 'runner@example.test', $now);
        $oldToken = $this->latestToken();
        self::assertTrue($newsletter->unsubscribe($oldToken, ' Trop de messages ', $now));
        self::assertSame('Trop de messages', $this->database->fetchOne('SELECT quit_reason FROM newsletter_subscriptions'));
        $newsletter->subscribe('00000000-0000-0000-0000-000000000002', 'RUNNER@example.test', $now);
        self::assertEquals(1, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
        self::assertSame('00000000-0000-0000-0000-000000000001', $this->database->fetchOne('SELECT id::text FROM newsletter_subscriptions'));
        self::assertTrue($this->database->fetchOne('SELECT subscribed FROM newsletter_subscriptions'));
        self::assertNull($this->database->fetchOne('SELECT quit_at FROM newsletter_subscriptions'));
        self::assertNull($this->database->fetchOne('SELECT quit_reason FROM newsletter_subscriptions'));
        self::assertFalse($newsletter->unsubscribe($oldToken, null, $now->modify('+1 hour')));
        $newToken = $this->latestToken();
        self::assertNotSame($oldToken, $newToken);
        self::assertTrue($newsletter->unsubscribe($newToken, ' ', $now->modify('+1 hour')));
        self::assertNull($this->database->fetchOne('SELECT quit_reason FROM newsletter_subscriptions'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTokens')]
    public function testTamperedAndWrongPurposeLinksCannotUnsubscribe(string $kind): void
    {
        $newsletter = $this->newsletter();
        $newsletter->subscribe('00000000-0000-0000-0000-000000000001', 'runner@example.test', new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $token = $this->latestToken();
        [$payload,$signature] = explode('.', $token);
        $invalid = match ($kind) {
            'signature' => $payload.'.'.str_repeat('0', 64),
            'purpose' => $payload.'.'.hash_hmac('sha256', "account.password_reset\0".$payload, 'test-only-newsletter-secret'),
            'secret' => $payload.'.'.hash_hmac('sha256', "newsletter.unsubscribe\0".$payload, 'other-secret'),
            'payload' => '0'.substr($payload, 1).'.'.$signature,
            'malformed' => 'not-a-signed-token',
            default => throw new \LogicException('Invalid test scenario.'),
        };
        try {
            $newsletter->unsubscribe($invalid, null, new \DateTimeImmutable('2026-10-10T11:00:00Z'));
            self::fail('Invalid authority must fail closed.');
        } catch (\InvalidArgumentException) {
            self::assertTrue($this->database->fetchOne('SELECT subscribed FROM newsletter_subscriptions'));
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidTokens(): iterable
    {
        foreach (['signature', 'purpose', 'secret', 'payload', 'malformed'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testInvalidEmailCannotCreateNewsletterOrMailIntent(): void
    {
        try {
            $this->newsletter()->subscribe('00000000-0000-0000-0000-000000000001', "invalid\r\n@example.test", new \DateTimeImmutable('2026-10-10Z'));
            self::fail('Invalid email must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
            self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        }
    }

    public function testWelcomeIntentRevalidatesSubscriptionAndNeverSendsAfterUnsubscribe(): void
    {
        $newsletter = $this->newsletter();
        $newsletter->subscribe('00000000-0000-0000-0000-000000000001', 'runner@example.test', new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $token = $this->latestToken();
        $newsletter->unsubscribe($token, null, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
        $sender = new ControlledNewsletterMail();
        $delivery = new \App\Application\Newsletter\DeliverNewsletterWelcome(new DbalNewsletterStore($this->database), new HmacNewsletterTokens('test-only-newsletter-secret'), new \App\Infrastructure\Operations\DbalCredentialDeliveryStore($this->database), $sender);
        $key = $this->database->fetchOne('SELECT effect_key FROM outbox_messages');
        self::assertIsString($key);
        self::assertFalse($delivery->deliver($key, $token, new \DateTimeImmutable('2026-10-10T10:02:00Z')));
        self::assertSame([], $sender->sent);
        self::assertSame('expired', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages"));
    }

    public function testWelcomeIsSentOnceWithTheAuthoritativeAddressAndSignedUnsubscribeLink(): void
    {
        $this->newsletter()->subscribe('00000000-0000-0000-0000-000000000001', ' Runner@Example.TEST ', new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $token = $this->latestToken();
        $sender = new ControlledNewsletterMail();
        $delivery = new \App\Application\Newsletter\DeliverNewsletterWelcome(new DbalNewsletterStore($this->database), new HmacNewsletterTokens('test-only-newsletter-secret'), new \App\Infrastructure\Operations\DbalCredentialDeliveryStore($this->database), $sender);
        $key = $this->database->fetchOne('SELECT effect_key FROM outbox_messages');
        self::assertIsString($key);
        self::assertTrue($delivery->deliver($key, $token, new \DateTimeImmutable('2026-10-10T10:01:00Z')));
        self::assertFalse($delivery->deliver($key, $token, new \DateTimeImmutable('2026-10-10T10:02:00Z')));
        self::assertSame([['email' => 'runner@example.test', 'token' => $token, 'message_id' => hash('sha256', $key).'@runnerbib.local']], $sender->sent);
        self::assertSame('accepted', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages"));
    }

    public function testAmbiguousWelcomeDeliveryRemainsUnknownAndCannotBeBlindlyReplayed(): void
    {
        $this->newsletter()->subscribe('00000000-0000-0000-0000-000000000001', 'runner@example.test', new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $token = $this->latestToken();
        $sender = new ControlledNewsletterMail();
        $sender->fail = true;
        $delivery = new \App\Application\Newsletter\DeliverNewsletterWelcome(new DbalNewsletterStore($this->database), new HmacNewsletterTokens('test-only-newsletter-secret'), new \App\Infrastructure\Operations\DbalCredentialDeliveryStore($this->database), $sender);
        $key = $this->database->fetchOne('SELECT effect_key FROM outbox_messages');
        self::assertIsString($key);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $delivery->deliver($key, $token, new \DateTimeImmutable('2026-10-10T10:01:00Z'));
                self::fail('Ambiguous delivery must require reconciliation.');
            } catch (\App\Application\Operations\UncertainMailDelivery $exception) {
                self::assertStringNotContainsString('Private SMTP', $exception->getMessage());
                self::assertSame('unknown', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages"));
            }
        }
        self::assertCount(1, $sender->sent);
        self::assertSame('unknown', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages"));
    }

    public function testWelcomeOutboxHandlerValidatesPurposeAndDispatchesTheIntentOnlyOnce(): void
    {
        $this->newsletter()->subscribe('00000000-0000-0000-0000-000000000001', 'runner@example.test', new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $token = $this->latestToken();
        $sender = new ControlledNewsletterMail();
        $tokens = new HmacNewsletterTokens('test-only-newsletter-secret');
        $delivery = new \App\Application\Newsletter\DeliverNewsletterWelcome(new DbalNewsletterStore($this->database), $tokens, new \App\Infrastructure\Operations\DbalCredentialDeliveryStore($this->database), $sender);
        $handler = new \App\Infrastructure\Newsletter\NewsletterMessageHandler($delivery, $tokens);
        self::assertTrue($handler->supports('newsletter.subscription_registered'));
        self::assertFalse($handler->supports('identity.credential_mail'));
        $key = $this->database->fetchOne('SELECT effect_key FROM outbox_messages');
        self::assertIsString($key);
        $message = new \App\Application\Operations\OutboxMessage('00000000-0000-0000-0000-000000000001', $key, 'newsletter_subscription', '00000000-0000-0000-0000-000000000001', 1, 'newsletter.subscription_registered', 1, json_encode(['unsubscribe_token' => $token], JSON_THROW_ON_ERROR), new \DateTimeImmutable('2026-10-10T10:00:00Z'));
        $handler($message);
        $handler($message);
        self::assertCount(1, $sender->sent);
        self::assertSame('accepted', $this->database->fetchOne("SELECT delivery_state->'smtp'->>'state' FROM outbox_messages"));
    }

    public function testMailIntentFailureRollsBackTheIndependentSignup(): void
    {
        $this->database->executeStatement('DROP TABLE outbox_messages');
        try {
            $this->newsletter()->subscribe('00000000-0000-0000-0000-000000000001', 'runner@example.test', new \DateTimeImmutable('2026-10-10Z'));
            self::fail('Signup must not commit without its durable mail intent.');
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        }
    }

    public function testConcurrentNormalizedSignupsCreateOnlyOneSubscriptionAndOneMailIntent(): void
    {
        $name = 'newsletter_'.bin2hex(random_bytes(6));
        $this->database->executeQuery("SELECT set_config('application_name',?,false)", [$name]);
        $code = <<<'CODE'
require 'vendor/autoload.php';
$database=\Doctrine\DBAL\DriverManager::getConnection(json_decode(getenv('NEWSLETTER_TEST_PARAMS'),true,flags:JSON_THROW_ON_ERROR));
$database->beginTransaction();
$effects=new \App\Infrastructure\Identity\OutboxIdentityEffects(new \App\Infrastructure\Operations\DbalOutboxWriter($database),new \Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory([]),new \App\Infrastructure\Operations\AesCredentialTokenProtector('credential-token-secret-for-tests-0123456789'));
$newsletter=new \App\Application\Newsletter\ManageNewsletter(new \App\Infrastructure\Newsletter\DbalNewsletterStore($database),new \App\Infrastructure\Newsletter\HmacNewsletterTokens('test-only-newsletter-secret'),new \App\Infrastructure\Operations\DbalOutboxWriter($database),new \App\Infrastructure\Operations\DbalTransactionManager($database),new \App\Infrastructure\Operations\DbalAuditWriter($database),$effects,'test-approved-newsletter-v1');
$newsletter->subscribe('00000000-0000-0000-0000-000000000001','Runner@Example.TEST',new \DateTimeImmutable('2026-10-10T10:00:00Z'));
echo 'locked'; flush();
$deadline=microtime(true)+10;
do {
    $database->executeQuery('SELECT pg_stat_clear_snapshot()');
    $blocked=$database->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'",[getenv('NEWSLETTER_PARENT_NAME')]);
    if (1===$blocked) { $database->commit(); echo 'released'; exit(0); }
    usleep(20000);
} while (microtime(true)<$deadline);
$database->rollBack(); exit(1);
CODE;
        $process = new \Symfony\Component\Process\Process(['php', '-r', $code], dirname(__DIR__, 3), ['NEWSLETTER_TEST_PARAMS' => json_encode($this->database->getParams(), JSON_THROW_ON_ERROR), 'NEWSLETTER_PARENT_NAME' => $name]);
        try {
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'locked')));
            $this->newsletter()->subscribe('00000000-0000-0000-0000-000000000002', ' runner@example.test ', new \DateTimeImmutable('2026-10-10T10:01:00Z'));
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertSame('lockedreleased', $process->getOutput());
            self::assertEquals(1, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
            self::assertEquals(1, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
            self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='newsletter.preference_granted'"));
            self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM accounts'));
        } finally {
            $process->stop();
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOutboxMessages')]
    public function testWelcomeHandlerRejectsInvalidOrOtherSubscriptionMessages(string $type, int $version, string $payload): void
    {
        $tokens = new HmacNewsletterTokens('test-only-newsletter-secret');
        $handler = new \App\Infrastructure\Newsletter\NewsletterMessageHandler(new \App\Application\Newsletter\DeliverNewsletterWelcome(new DbalNewsletterStore($this->database), $tokens, new \App\Infrastructure\Operations\DbalCredentialDeliveryStore($this->database), new ControlledNewsletterMail()), $tokens);
        $this->expectException(\InvalidArgumentException::class);
        $handler(new \App\Application\Operations\OutboxMessage('00000000-0000-0000-0000-000000000001', 'fixture', 'newsletter_subscription', '00000000-0000-0000-0000-000000000001', 1, $type, $version, $payload, new \DateTimeImmutable('2026-10-10Z')));
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function invalidOutboxMessages(): iterable
    {
        yield 'unsupported' => ['other', 1, '{}'];
        yield 'version' => ['newsletter.subscription_registered', 2, '{}'];
        yield 'invalid JSON' => ['newsletter.subscription_registered', 1, '{'];
        yield 'scalar' => ['newsletter.subscription_registered', 1, 'null'];
        yield 'missing token' => ['newsletter.subscription_registered', 1, '{}'];
        $token = (new HmacNewsletterTokens('test-only-newsletter-secret'))->issue('00000000-0000-0000-0000-000000000002', new \DateTimeImmutable('2026-10-10Z'));
        yield 'different aggregate' => ['newsletter.subscription_registered', 1, json_encode(['unsubscribe_token' => $token], JSON_THROW_ON_ERROR)];
    }

    public function testNewsletterPersistenceRejectsMutationsOutsideTheOwningBusinessTransaction(): void
    {
        $store = new DbalNewsletterStore($this->database);
        $now = new \DateTimeImmutable('2026-10-10Z');
        try {
            $store->subscribe('00000000-0000-0000-0000-000000000001', 'runner@example.test', $now);
            self::fail('Subscription must share the mail-intent transaction.');
        } catch (\LogicException) {
            self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
        }
        try {
            $store->unsubscribe(new \App\Application\Newsletter\NewsletterUnsubscribeAuthority('00000000-0000-0000-0000-000000000001', $now), null, $now);
            self::fail('Lifecycle mutation must own a transaction.');
        } catch (\LogicException) {
            self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
        }
        self::assertEquals(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
    }

    private function latestToken(): string
    {
        $token = $this->database->fetchOne("SELECT payload->>'unsubscribe_token' FROM outbox_messages WHERE event_type='newsletter.subscription_registered' ORDER BY created_at DESC,id DESC LIMIT 1");
        self::assertIsString($token);

        return $token;
    }

    public function testUnconfiguredPolicyRefusesEveryGrantBeforeEmailDependentOutcomes(): void
    {
        $newsletter = $this->newsletter(null);
        foreach (['new@example.test', 'bad-email', '  '] as $email) {
            try {
                $newsletter->subscribe('00000000-0000-0000-0000-000000000001', $email, new \DateTimeImmutable('2026-10-10Z'));
                self::fail('Every grant needs a configured policy before storage or email validation.');
            } catch (\DomainException $error) {
                self::assertInstanceOf(\App\Application\Newsletter\UnconfiguredNewsletterPolicy::class, $error);
                self::assertSame('Newsletter policy is not configured.', $error->getMessage());
            }
        }
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testAnonymousNewsletterEvidenceIsVersionedGenerationBoundAndIdempotent(): void
    {
        $newsletter = $this->newsletter();
        $now = new \DateTimeImmutable('2026-10-10T10:00:00Z');
        $newsletter->subscribe('00000000-0000-0000-0000-000000000001', 'runner@example.test', $now);
        $oldToken = $this->latestToken();
        $newsletter->subscribe('00000000-0000-0000-0000-000000000002', 'runner@example.test', $now);
        $rows = $this->database->fetchAllAssociative("SELECT id,actor_account_id,service_principal,subject_id,authority_snapshot FROM administrative_audit WHERE action='newsletter.preference_granted'");
        self::assertCount(1, $rows);
        self::assertSame('00000000-0000-0000-0000-000000000001', $rows[0]['id']);
        self::assertNull($rows[0]['actor_account_id']);
        self::assertSame('public_newsletter', $rows[0]['service_principal']);
        self::assertSame('00000000-0000-0000-0000-000000000001', $rows[0]['subject_id']);
        self::assertIsString($rows[0]['authority_snapshot']);
        self::assertEquals(['scope' => 'public_newsletter', 'source' => 'public_signup', 'purpose' => 'newsletter_email', 'policy_version' => 'test-approved-newsletter-v1', 'generation' => '2026-10-10T10:00:00.000000+00:00', 'previous_enabled' => false, 'enabled' => true], json_decode($rows[0]['authority_snapshot'], true, 512, JSON_THROW_ON_ERROR));
        $unconfigured = $this->newsletter(null);
        self::assertTrue($unconfigured->unsubscribe($oldToken, 'Optional private reason', $now));
        self::assertFalse($unconfigured->unsubscribe($oldToken, null, $now));
        $newsletter->subscribe('00000000-0000-0000-0000-000000000003', 'runner@example.test', $now);
        self::assertFalse($unconfigured->unsubscribe($oldToken, null, $now));
        self::assertSame(2, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='newsletter.preference_granted'"));
        self::assertSame('2026-10-10T10:00:00.000001+00:00', $this->database->fetchOne("SELECT authority_snapshot->>'generation' FROM administrative_audit WHERE id='00000000-0000-0000-0000-000000000003'"));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='newsletter.preference_withdrawn' AND authority_snapshot->'policy_version'='null'::jsonb"));
        $withdrawal = $this->database->fetchOne("SELECT authority_snapshot::text FROM administrative_audit WHERE action='newsletter.preference_withdrawn'");
        self::assertIsString($withdrawal);
        self::assertStringNotContainsString('Optional private reason', $withdrawal);
        self::assertStringNotContainsString('runner@example.test', $withdrawal);
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM accounts'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM race_waitlist_memberships'));
    }

    public function testAnonymousEvidenceFailureRollsBackSignupWithdrawalAndRejoin(): void
    {
        $now = new \DateTimeImmutable('2026-10-10Z');
        $newsletter = $this->newsletter();
        $this->database->executeStatement("CREATE FUNCTION fail_newsletter_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Controlled newsletter evidence failure'; END; $$");
        $this->database->executeStatement('CREATE TRIGGER reject_newsletter_evidence BEFORE INSERT ON administrative_audit FOR EACH ROW EXECUTE FUNCTION fail_newsletter_audit()');
        try {
            $newsletter->subscribe('00000000-0000-0000-0000-000000000001', 'runner@example.test', $now);
            self::fail('Signup cannot commit without consent evidence.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        }
        $this->database->executeStatement('DROP TRIGGER reject_newsletter_evidence ON administrative_audit');
        $newsletter->subscribe('00000000-0000-0000-0000-000000000001', 'runner@example.test', $now);
        $token = $this->latestToken();
        $this->database->executeStatement('CREATE TRIGGER reject_newsletter_evidence BEFORE INSERT ON administrative_audit FOR EACH ROW EXECUTE FUNCTION fail_newsletter_audit()');
        try {
            $newsletter->unsubscribe($token, 'Must roll back', $now);
            self::fail('Withdrawal cannot commit without evidence.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertTrue($this->database->fetchOne('SELECT subscribed FROM newsletter_subscriptions'));
            self::assertNull($this->database->fetchOne('SELECT quit_at FROM newsletter_subscriptions'));
            self::assertNull($this->database->fetchOne('SELECT quit_reason FROM newsletter_subscriptions'));
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        }
        $this->database->executeStatement('DROP TRIGGER reject_newsletter_evidence ON administrative_audit');
        self::assertTrue($newsletter->unsubscribe($token, null, $now));
        $this->database->executeStatement('CREATE TRIGGER reject_newsletter_evidence BEFORE INSERT ON administrative_audit FOR EACH ROW EXECUTE FUNCTION fail_newsletter_audit()');
        try {
            $newsletter->subscribe('00000000-0000-0000-0000-000000000002', 'runner@example.test', $now);
            self::fail('Rejoin generation and welcome intent must roll back with missing evidence.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertFalse($this->database->fetchOne('SELECT subscribed FROM newsletter_subscriptions'));
            self::assertSame('2026-10-10 00:00:00+00', $this->database->fetchOne('SELECT join_at::text FROM newsletter_subscriptions'));
            self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        }
        $this->database->executeStatement('DROP TRIGGER reject_newsletter_evidence ON administrative_audit');
        $newsletter->subscribe('00000000-0000-0000-0000-000000000002', 'runner@example.test', $now);
        self::assertSame(3, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        self::assertSame(2, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        self::assertFalse($newsletter->unsubscribe($token, null, $now));
    }

    public function testAnActiveEmailGetsTheSameUnconfiguredPolicyResponseAsNewEmails(): void
    {
        $this->newsletter()->subscribe('00000000-0000-0000-0000-000000000001', 'existing@example.test', new \DateTimeImmutable('2026-10-10Z'));
        foreach (['existing@example.test', 'new@example.test', 'invalid'] as $email) {
            try {
                $this->newsletter('  ')->subscribe('00000000-0000-0000-0000-000000000002', $email, new \DateTimeImmutable('2026-10-10Z'));
                self::fail('Policy response must not reveal prior subscription.');
            } catch (\App\Application\Newsletter\UnconfiguredNewsletterPolicy $error) {
                self::assertSame('Newsletter policy is not configured.', $error->getMessage());
            }
        }
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
    }

    public function testConfiguredWithdrawalKeepsTheCompleteConsentEvidence(): void
    {
        $newsletter = $this->newsletter();
        $now = new \DateTimeImmutable('2026-10-10T10:00:00Z');
        $newsletter->subscribe('00000000-0000-0000-0000-000000000001', 'runner@example.test', $now);
        self::assertSame(1, $this->database->fetchOne('SELECT payload_version FROM outbox_messages'));
        self::assertSame(1, $this->database->fetchOne('SELECT aggregate_version FROM outbox_messages'));
        self::assertTrue($newsletter->unsubscribe($this->latestToken(), ' Reason ', $now));
        self::assertSame('Reason', $this->database->fetchOne('SELECT quit_reason FROM newsletter_subscriptions'));
        $raw = $this->database->fetchOne("SELECT authority_snapshot::text FROM administrative_audit WHERE action='newsletter.preference_withdrawn'");
        self::assertIsString($raw);
        $facts = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($facts);
        foreach (['scope' => 'public_newsletter', 'source' => 'signed_unsubscribe', 'purpose' => 'newsletter_email', 'policy_version' => 'test-approved-newsletter-v1', 'generation' => '2026-10-10T10:00:00.000000+00:00', 'previous_enabled' => true, 'enabled' => false] as $key => $expected) {
            self::assertArrayHasKey($key, $facts);
            self::assertSame($expected, $facts[$key]);
        }
    }

    private function newsletter(?string $policyVersion = ' test-approved-newsletter-v1 '): ManageNewsletter
    {
        return new ManageNewsletter(new DbalNewsletterStore($this->database), new HmacNewsletterTokens('test-only-newsletter-secret'), new DbalOutboxWriter($this->database), new DbalTransactionManager($this->database), new \App\Infrastructure\Operations\DbalAuditWriter($this->database), self::getContainer()->get(\App\Application\Identity\IdentityEffects::class), $policyVersion);
    }
}
