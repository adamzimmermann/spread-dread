<?php

namespace App\Tests\Functional;

use App\Entity\Game;
use App\Entity\Pick;
use App\Tests\Support\FakeEspn;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TournamentSyncCommandTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeEspn::reset();
    }

    private function runSync(int $year): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:tournament:sync'));
        $tester->execute(['--year' => (string) $year]);
        return $tester;
    }

    /** A bracket for $year with one first-round game ESPN reports as final (70-60). */
    private function finishedGame(string $prefix, int $year): Game
    {
        $bracket = $this->createBracket($this->createUser("{$prefix}_p1"), $this->createUser("{$prefix}_p2"));
        $bracket->setYear($year);
        $home = $this->createTeam("{$prefix} Home", 1);
        $away = $this->createTeam("{$prefix} Away", 16);
        $next = $this->createGame($bracket, $home, $away, 2, 1);
        $next->setTeam1(null)->setTeam2(null);
        $game = $this->createGame($bracket, $home, $away, 1, 1);
        $game->setExternalGameId("{$prefix}-evt")->setNextGame($next)->setSpread(5.0)->setSpreadTeam($home);
        $pick = (new Pick())->setPlayer(1)->setTeam($home);
        $game->addPick($pick);
        $this->em->persist($pick);
        $this->em->flush();

        FakeEspn::addEvent($year, 3, FakeEspn::event(
            "{$prefix}-evt", "NCAA Men's Basketball Championship - East Region - 1st Round",
            ['name' => "{$prefix} Home", 'seed' => 1, 'score' => 70],
            ['name' => "{$prefix} Away", 'seed' => 16, 'score' => 60],
            true,
        ));
        return $game;
    }

    public function testSyncScoresEvaluatesAndAdvances(): void
    {
        $game = $this->finishedGame('ts_ok', 2027);

        $tester = $this->runSync(2027);

        $tester->assertCommandIsSuccessful();
        $this->em->refresh($game);
        $this->assertTrue($game->isComplete());
        $this->assertTrue($game->getPickForPlayer(1)->isWinner(), 'Covered 5 by winning by 10');
        $this->assertSame('ts_ok Home', $game->getNextGame()->getTeam1()->getName());
    }

    public function testSyncIsIdempotentAndIgnoresOtherYears(): void
    {
        $current = $this->finishedGame('ts_idem', 2027);
        $old = $this->finishedGame('ts_old', 2026);

        $this->runSync(2027);
        $second = $this->runSync(2027);

        $this->assertStringContainsString('0 game(s) finalised', $second->getDisplay());
        $this->em->refresh($current);
        $this->em->refresh($old);
        $winners = array_filter($current->getPicks()->toArray(), fn (Pick $p) => $p->isWinner() === true);
        $this->assertCount(1, $winners);
        $this->assertFalse($old->isComplete(), 'Other years are not touched');
    }

    public function testSyncWithNoBracketsDoesNothing(): void
    {
        $tester = $this->runSync(2031);
        $tester->assertCommandIsSuccessful();
        $this->assertSame([], FakeEspn::$requests);
    }
}
