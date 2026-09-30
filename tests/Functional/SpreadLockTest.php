<?php

namespace App\Tests\Functional;

use App\Entity\Game;
use App\Entity\Pick;
use App\Service\EspnApiService;
use App\Tests\Support\FakeEspn;

class SpreadLockTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeEspn::reset();
    }

    /** One first-round game with an ESPN id and a 3.5 spread already stored. */
    private function gameWithSpread(string $prefix): Game
    {
        $bracket = $this->createBracket($this->createUser("{$prefix}_p1"), $this->createUser("{$prefix}_p2"));
        $home = $this->createTeam("{$prefix} Home", 1);
        $away = $this->createTeam("{$prefix} Away", 16);
        $game = $this->createGame($bracket, $home, $away);
        $game->setExternalGameId("{$prefix}-evt")->setSpread(3.5)->setSpreadTeam($home);
        $this->em->flush();
        return $game;
    }

    private function pull(Game $game): array
    {
        return static::getContainer()->get(EspnApiService::class)->pullSpreads($game->getBracket(), 1);
    }

    public function testSpreadRefreshesWhileNoPicks(): void
    {
        $game = $this->gameWithSpread('sl_open');
        FakeEspn::setSummary('sl_open-evt', 'sl_open Home', 'sl_open Away', 5.5);

        $result = $this->pull($game);

        $this->assertFalse($result['locked']);
        $this->assertSame(5.5, $game->getSpread());
    }

    public function testSpreadIsLockedOnceARoundHasAPick(): void
    {
        $game = $this->gameWithSpread('sl_lock');
        $pick = (new Pick())->setPlayer(1)->setTeam($game->getTeam1());
        $game->addPick($pick);
        $this->em->persist($pick);
        $this->em->flush();
        FakeEspn::setSummary('sl_lock-evt', 'sl_lock Home', 'sl_lock Away', 9.0);

        $result = $this->pull($game);

        $this->assertTrue($result['locked']);
        $this->assertSame(3.5, $game->getSpread());
        $this->assertSame([], FakeEspn::$requests, 'A locked round makes no ESPN calls');
    }

    public function testMissingLineKeepsExistingSpread(): void
    {
        $game = $this->gameWithSpread('sl_gone');
        FakeEspn::setSummary('sl_gone-evt', 'sl_gone Home', 'sl_gone Away', null);

        $result = $this->pull($game);

        $this->assertSame(3.5, $game->getSpread());
        $this->assertSame([], $result['unmatched'], 'Existing spread: no "set manually" warning');
    }

    public function testButtonShowsLockedState(): void
    {
        $game = $this->gameWithSpread('sl_btn');
        $pick = (new Pick())->setPlayer(1)->setTeam($game->getTeam1());
        $game->addPick($pick);
        $this->em->persist($pick);
        $this->em->flush();

        $this->loginViaForm('sl_btn_p1');
        $this->client->request('GET', '/brackets/' . $game->getBracket()->getId());
        $this->assertSelectorTextContains('#btn-spreads', 'Spreads locked');
        $this->assertSelectorExists('#btn-spreads[disabled]');
    }
}
