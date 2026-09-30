<?php

namespace App\Tests\Functional;

use App\Entity\Bracket;
use App\Service\TournamentCalendar;
use App\Tests\Support\FakeEspn;

class BracketCreateGateTest extends WebTestCase
{
    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        FakeEspn::reset();
        $this->year = static::getContainer()->get(TournamentCalendar::class)->activeYear();
    }

    private function openTournament(): void
    {
        FakeEspn::firstRound($this->year);
        static::getContainer()->get(TournamentCalendar::class)->setEastOpponent($this->year, 'West');
    }

    private function submitCreate(string $p1, string $p2): void
    {
        $this->createUser($p1);
        $this->createUser($p2);
        $this->loginViaForm($p1);
        $this->client->request('POST', '/brackets/create', [
            '_token' => $this->csrfToken(),
            'name' => "$p1 bracket",
            'year' => 2020, // Must be ignored.
            'opponent_username' => $p2,
        ]);
    }

    private function bracketNamed(string $name): ?Bracket
    {
        return $this->em->getRepository(Bracket::class)->findOneBy(['name' => $name]);
    }

    public function testFormHasNoYearField(): void
    {
        $this->openTournament();
        $this->createUser('cg_form_user');
        $this->loginViaForm('cg_form_user');

        $this->client->request('GET', '/brackets/create');
        $this->assertSelectorNotExists('input[name="year"]');
        $this->assertSelectorTextContains('body', (string) $this->year);
    }

    public function testCreateUsesTheActiveYearAndLoadsTeams(): void
    {
        $this->openTournament();
        $this->submitCreate('cg_ok_p1', 'cg_ok_p2');

        $bracket = $this->bracketNamed('cg_ok_p1 bracket');
        $this->assertNotNull($bracket);
        $this->assertSame($this->year, $bracket->getYear());
        $this->assertResponseRedirects('/brackets/' . $bracket->getId());
    }

    public function testCreateIsRefusedBeforeTeamsAreAnnounced(): void
    {
        static::getContainer()->get(TournamentCalendar::class)->setEastOpponent($this->year, 'West');
        $this->submitCreate('cg_noteams_p1', 'cg_noteams_p2');

        $this->assertNull($this->bracketNamed('cg_noteams_p1 bracket'));
        $this->assertSelectorTextContains('body', 'open after Selection Sunday');
    }

    public function testCreateIsRefusedUntilThePairingIsSet(): void
    {
        FakeEspn::firstRound($this->year);
        $this->submitCreate('cg_nopair_p1', 'cg_nopair_p2');

        $this->assertNull($this->bracketNamed('cg_nopair_p1 bracket'));
        $this->assertSelectorTextContains('body', 'open after Selection Sunday');
    }

    public function testCreateIsRefusedWhenEspnFails(): void
    {
        $this->openTournament();
        FakeEspn::$failAll = true;
        $this->submitCreate('cg_down_p1', 'cg_down_p2');

        $this->assertNull($this->bracketNamed('cg_down_p1 bracket'));
        $this->assertSelectorTextContains('body', 'open after Selection Sunday');
    }
}
