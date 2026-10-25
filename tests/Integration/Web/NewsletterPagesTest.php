<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Application\Newsletter\NewsletterTokens;
use App\Tests\Support\IsolatedWebTestCase;

final class NewsletterPagesTest extends IsolatedWebTestCase
{
    /** @var array<array-key, mixed> */
    private array $previousServer = [];
    /** @var array<array-key, mixed> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        $this->previousServer = $_SERVER;
        $this->previousEnvironment = $_ENV;
        $_SERVER['NEWSLETTER_POLICY_VERSION'] = $_ENV['NEWSLETTER_POLICY_VERSION'] = 'test-only-newsletter-v1';
        $_SERVER['NEWSLETTER_SIGNING_SECRET'] = $_ENV['NEWSLETTER_SIGNING_SECRET'] = 'newsletter-current-fixture';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_SERVER = $this->previousServer;
        $_ENV = $this->previousEnvironment;
    }

    public function testUnsubscribeUsesDedicatedKeyWhenApplicationSecretChanges(): void
    {
        $id = '00000000-0000-0000-0000-000000000031';
        $joinedAt = new \DateTimeImmutable('2026-10-10Z');
        $this->database->insert('newsletter_subscriptions', ['id' => $id, 'email' => 'rotation@example.test', 'email_normalized' => 'rotation@example.test', 'join_at' => $joinedAt->format(DATE_ATOM), 'subscribed' => true]);
        $token = (new \App\Infrastructure\Newsletter\HmacNewsletterTokens('newsletter-current-fixture'))->issue($id, $joinedAt);
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'rotated-application-fixture';
        $client = self::createClient();
        $client->request('GET', '/newsletter/desinscription/'.$token);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->database->fetchOne('SELECT subscribed FROM newsletter_subscriptions'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testUnconfiguredSignupIsUniformAndSignedWithdrawalStillWorks(): void
    {
        $_SERVER['NEWSLETTER_POLICY_VERSION'] = $_ENV['NEWSLETTER_POLICY_VERSION'] = '';
        $id = '00000000-0000-0000-0000-000000000001';
        $joinedAt = new \DateTimeImmutable('2026-10-10Z');
        $this->database->insert('newsletter_subscriptions', ['id' => $id, 'email' => 'existing@example.test', 'email_normalized' => 'existing@example.test', 'join_at' => $joinedAt->format(DATE_ATOM), 'subscribed' => true]);
        $client = self::createClient([], ['REMOTE_ADDR' => '2001:db8:'.bin2hex(random_bytes(2)).':'.bin2hex(random_bytes(2)).'::1']);
        $crawler = $client->request('GET', '/newsletter');
        foreach (['existing@example.test', 'missing@example.test', 'invalid'] as $email) {
            $client->submit($crawler->selectButton('M’inscrire à la newsletter')->form(['email' => $email]));
            self::assertResponseStatusCodeSame(503);
            self::assertSelectorTextContains('[role="status"]', 'temporairement indisponible');
        }
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        $token = self::getContainer()->get(NewsletterTokens::class)->issue($id, $joinedAt);
        $client->request('GET', '/newsletter/desinscription/'.$token);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->database->fetchOne('SELECT subscribed FROM newsletter_subscriptions'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testIndependentSignupAndSignedUnsubscribeRequireNoAccountOrReason(): void
    {
        $client = self::createClient([], ['REMOTE_ADDR' => '2001:db8:'.bin2hex(random_bytes(2)).':'.bin2hex(random_bytes(2)).'::1']);
        $crawler = $client->request('GET', '/newsletter');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/newsletter', ['email' => 'runner@example.test', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $client->submit($crawler->selectButton('M’inscrire à la newsletter')->form(['email' => 'invalid']));
        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
        $form = $crawler->selectButton('M’inscrire à la newsletter')->form(['email' => ' Runner@Example.test ']);
        $client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'prise en compte');
        self::assertSame('runner@example.test', $this->database->fetchOne('SELECT email_normalized FROM newsletter_subscriptions'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM accounts'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM race_waitlist_memberships'));
        $id = $this->database->fetchOne('SELECT id::text FROM newsletter_subscriptions');
        $joinedAt = $this->database->fetchOne('SELECT join_at::text FROM newsletter_subscriptions');
        self::assertIsString($id);
        self::assertIsString($joinedAt);
        $token = self::getContainer()->get(NewsletterTokens::class)->issue($id, new \DateTimeImmutable($joinedAt));
        $client->request('GET', '/newsletter/desinscription/'.$token);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->database->fetchOne('SELECT subscribed FROM newsletter_subscriptions'));
        self::assertNull($this->database->fetchOne('SELECT quit_reason FROM newsletter_subscriptions'));
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
        $client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->database->fetchOne('SELECT subscribed FROM newsletter_subscriptions'));
        $client->request('GET', '/newsletter/desinscription/'.$token);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->database->fetchOne('SELECT subscribed FROM newsletter_subscriptions'));
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
        $rejoinedAt = $this->database->fetchOne('SELECT join_at::text FROM newsletter_subscriptions');
        self::assertIsString($rejoinedAt);
        $newToken = self::getContainer()->get(NewsletterTokens::class)->issue($id, new \DateTimeImmutable($rejoinedAt));
        $client->request('POST', '/newsletter/desinscription/'.$newToken, ['raison' => 'Trop de courriels']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->database->fetchOne('SELECT subscribed FROM newsletter_subscriptions'));
        self::assertSame('Trop de courriels', $this->database->fetchOne('SELECT quit_reason FROM newsletter_subscriptions'));
        $client->request('GET', '/newsletter/desinscription/forged');
        self::assertResponseStatusCodeSame(400);
    }

    public function testSignupRateLimitDoesNotCreateDuplicateWelcomeIntents(): void
    {
        $client = self::createClient([], ['REMOTE_ADDR' => '2001:db8:'.bin2hex(random_bytes(2)).':'.bin2hex(random_bytes(2)).'::1']);
        $crawler = $client->request('GET', '/newsletter');
        $form = $crawler->selectButton('M’inscrire à la newsletter')->form(['email' => 'runner@example.test']);
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $client->submit($form);
            self::assertResponseIsSuccessful();
        }
        $client->submit($form);
        self::assertResponseStatusCodeSame(429);
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM newsletter_subscriptions'));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM outbox_messages WHERE event_type='newsletter.subscription_registered'"));
    }
}
