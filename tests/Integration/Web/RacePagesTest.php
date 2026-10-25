<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Support\IsolatedWebTestCase;

final class RacePagesTest extends IsolatedWebTestCase
{
    public function testSearchHasFrenchLabelsAndEmptyResult(): void
    {
        $client = self::createClient();
        $client->request('GET', '/courses?nom=aucune-course-'.bin2hex(random_bytes(12)));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Trouvez votre prochaine course');
        self::assertSelectorExists('label[for="nom"]');
        self::assertSelectorTextContains('[role="status"]', 'Aucune course');
        self::assertSelectorExists('form[method="get"]');
        self::assertSelectorTextContains('details.site-menu > summary', 'Menu');
        self::assertSelectorTextContains('fieldset legend', 'Affiner la recherche');
        self::assertSelectorExists('section[aria-labelledby="course-results-heading"]');
    }

    public function testSearchRejectsMalformedFiltersWithFrenchError(): void
    {
        $client = self::createClient();
        $client->request('GET', '/courses?distance_min=-1');
        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('[role="alert"]', 'Vérifiez les filtres');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFilters')]
    public function testInvalidFilterIsRejected(string $query): void
    {
        $client = self::createClient();
        $client->request('GET', '/courses?'.$query);
        self::assertResponseStatusCodeSame(400);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFilters(): iterable
    {
        yield 'integer' => ['distance_min=abc'];
        yield 'overflow' => ['distance_min=999999999999999999999999'];
        yield 'date' => ['debut=invalid'];
        yield 'rollover' => ['debut=2027-02-31'];
        yield 'cursor' => ['page=invalid!'];
        yield 'inverted distance' => ['distance_min=200&distance_max=100'];
    }

    public function testAllFiltersHaveUsableControls(): void
    {
        $client = self::createClient();
        $client->request('GET', '/courses?nom=impossible-race-example&ville=Paris&pays=FR&sport=running&debut=2027-01-01&fin=2027-12-31&distance_min=1&distance_max=100000');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="distance_min"][value="1"]');
        self::assertSelectorExists('input[name="debut"][type="date"]');
    }

    public function testPublishedRaceDetailIsPublicAndDraftIsHidden(): void
    {
        foreach (['published', 'draft'] as $index => $publication) {
            $this->database->insert('races', [
                'id' => sprintf('00000000-0000-0000-0000-%012d', $index + 1),
                'name' => 'Marathon de Paris', 'starts_at' => '2027-04-04 07:00:00+00',
                'timezone' => 'Europe/Paris', 'sport_code' => 'running', 'distance_metres' => 42195,
                'city' => 'Paris', 'country_code' => 'FR', 'publication_state' => $publication,
                'created_at' => '2026-10-08 00:00:00+00', 'updated_at' => '2026-10-08 00:00:00+00',
            ]);
        }
        $client = self::createClient();
        $client->request('GET', '/courses/00000000-0000-0000-0000-000000000001');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Marathon de Paris');
        self::assertSelectorTextContains('main', '42.195 km');
        self::assertSelectorTextContains('main', 'Dossards');
        $client->request('GET', '/courses/00000000-0000-0000-0000-000000000002');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMalformedRaceIdHasControlledNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/courses/------------------------------------');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCursorNavigationKeepsFiltersAndDoesNotRepeatResults(): void
    {
        for ($index = 1; $index <= 21; ++$index) {
            $this->database->insert('races', [
                'id' => sprintf('00000000-0000-0000-0000-%012d', $index),
                'name' => sprintf('Paris course %02d', $index), 'starts_at' => '2027-04-04 07:00:00+00',
                'timezone' => 'Europe/Paris', 'sport_code' => 'running', 'distance_metres' => 10000,
                'city' => 'Paris', 'country_code' => 'FR', 'publication_state' => 'published',
                'created_at' => '2026-10-08 00:00:00+00', 'updated_at' => '2026-10-08 00:00:00+00',
            ]);
        }
        $client = self::createClient();
        $crawler = $client->request('GET', '/courses?ville=Paris');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(20, 'article');
        $next = $crawler->filter('a[rel="next"]')->link();
        self::assertStringContainsString('ville=Paris', $next->getUri());
        $client->click($next);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'article');
        self::assertSelectorTextContains('article', 'Paris course 21');
        self::assertSelectorNotExists('a[rel="next"]');
    }
}
