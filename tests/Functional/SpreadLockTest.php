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

    /** Game A (3.5, picked) plus game B in the same round: both teams, no spread, no picks. */
    private function lockedRoundWithLateGame(string $prefix): array
    {
        $gameA = $this->gameWithSpread($prefix);
        $pick = (new Pick())->setPlayer(1)->setTeam($gameA->getTeam1());
        $gameA->addPick($pick);
        $this->em->persist($pick);

        $home = $this->createTeam("{$prefix} Late Home", 8);
        $away = $this->createTeam("{$prefix} Late Away", 9);
        $gameB = $this->createGame($gameA->getBracket(), $home, $away, 1, 2);
        $gameB->setExternalGameId("{$prefix}-late-evt");
        $this->em->flush();

        return [$gameA, $gameB];
    }

    public function testLockedRoundStillFillsGamesWithoutASpread(): void
    {
        [$gameA, $gameB] = $this->lockedRoundWithLateGame('sl_late');
        FakeEspn::setSummary('sl_late-evt', 'sl_late Home', 'sl_late Away', 9.0);
        FakeEspn::setSummary('sl_late-late-evt', 'sl_late Late Home', 'sl_late Late Away', 6.5);

        $result = $this->pull($gameA);

        $this->assertTrue($result['locked']);
        $this->assertSame(3.5, $gameA->getSpread(), 'A picked game keeps its line');
        $this->assertSame(6.5, $gameB->getSpread(), 'A late-set game still gets its first line');
        $this->assertSame($gameB->getTeam1(), $gameB->getSpreadTeam());
        $this->assertSame(1, $result['matched']);
        $this->assertCount(1, FakeEspn::$requests, 'Only the eligible game is fetched');
    }

    public function testButtonStaysEnabledWhileALockedRoundHasGamesWithoutASpread(): void
    {
        [$gameA] = $this->lockedRoundWithLateGame('sl_latebtn');

        $this->loginViaForm('sl_latebtn_p1');
        $this->client->request('GET', '/brackets/' . $gameA->getBracket()->getId());
        $this->assertSelectorTextContains('#btn-spreads', 'Pull Spreads');
        $this->assertSelectorNotExists('#btn-spreads[disabled]');
    }

    public function testUnrecognisedFavouriteLeavesTheSpreadUnset(): void
    {
        $bracket = $this->createBracket($this->createUser('sl_nomatch_p1'), $this->createUser('sl_nomatch_p2'));
        $game = $this->createGame($bracket, $this->createTeam('sl_nomatch Home', 1), $this->createTeam('sl_nomatch Away', 16));
        $game->setExternalGameId('sl_nomatch-evt');
        $this->em->flush();
        FakeEspn::setSummary('sl_nomatch-evt', 'Somebody Else', 'Another Team', 4.5);

        $result = $this->pull($game);

        $this->assertNull($game->getSpread());
        $this->assertNull($game->getSpreadTeam());
        $this->assertSame([$game->getId()], $result['unmatched']);
    }
}
