<?php

namespace App\Tests\Service;

use App\Entity\Bracket;
use App\Entity\Game;
use App\Entity\Pick;
use App\Entity\Team;
use App\Repository\GameRepository;
use App\Service\ScoringService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ScoringServiceTest extends TestCase
{
    private ScoringService $service;

    protected function setUp(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $gameRepo = $this->createStub(GameRepository::class);
        $this->service = new ScoringService($em, $gameRepo);
    }

    private function makeTeam(string $name, int $seed = 1): Team
    {
        $team = new Team();
        $team->setName($name);
        $team->setSeed($seed);
        $team->setRegion('East');
        $team->setYear(2025);
        return $team;
    }

    private function makeCompletedGame(Team $team1, Team $team2, int $score1, int $score2, ?Team $spreadTeam = null, ?float $spread = null): Game
    {
        $game = new Game();
        $game->setRoundNumber(1);
        $game->setBracketPosition(1);
        $game->setTeam1($team1);
        $game->setTeam2($team2);
        $game->setTeam1Score($score1);
        $game->setTeam2Score($score2);
        $game->setIsComplete(true);
        $game->setWinner($score1 > $score2 ? $team1 : $team2);

        if ($spreadTeam && $spread !== null) {
            $game->setSpreadTeam($spreadTeam);
            $game->setSpread($spread);
        }

        return $game;
    }

    public function testFavoriteCoversSpread(): void
    {
        $duke = $this->makeTeam('Duke', 1);
        $norfolk = $this->makeTeam('Norfolk St', 16);

        // Duke wins 82-65, spread Duke -15.5 -> Duke covers (margin 17 > 15.5)
        $game = $this->makeCompletedGame($duke, $norfolk, 82, 65, $duke, 15.5);

        $pick = new Pick();
        $pick->setPlayer(1);
        $pick->setTeam($duke);
        $game->addPick($pick);

        $this->service->evaluatePicks($game);

        $this->assertTrue($pick->isWinner());
    }

    public function testFavoriteDoesNotCoverSpread(): void
    {
        $duke = $this->makeTeam('Duke', 1);
        $norfolk = $this->makeTeam('Norfolk St', 16);

        // Duke wins 82-70, spread Duke -15.5 -> Duke doesn't cover (margin 12 < 15.5)
        $game = $this->makeCompletedGame($duke, $norfolk, 82, 70, $duke, 15.5);

        $pick = new Pick();
        $pick->setPlayer(1);
        $pick->setTeam($duke);
        $game->addPick($pick);

        $this->service->evaluatePicks($game);

        $this->assertFalse($pick->isWinner());
    }

    public function testUnderdogCoversSpread(): void
    {
        $duke = $this->makeTeam('Duke', 1);
        $norfolk = $this->makeTeam('Norfolk St', 16);

        // Duke wins 82-70, spread Duke -15.5 -> Norfolk covers (lost by 12, less than 15.5)
        $game = $this->makeCompletedGame($duke, $norfolk, 82, 70, $duke, 15.5);

        $pick = new Pick();
        $pick->setPlayer(1);
        $pick->setTeam($norfolk);
        $game->addPick($pick);

        $this->service->evaluatePicks($game);

        $this->assertTrue($pick->isWinner());
    }

    public function testUnderdogDoesNotCoverSpread(): void
    {
        $duke = $this->makeTeam('Duke', 1);
        $norfolk = $this->makeTeam('Norfolk St', 16);

        // Duke wins 82-65, spread Duke -15.5 -> Norfolk doesn't cover (lost by 17 > 15.5)
        $game = $this->makeCompletedGame($duke, $norfolk, 82, 65, $duke, 15.5);

        $pick = new Pick();
        $pick->setPlayer(1);
        $pick->setTeam($norfolk);
        $game->addPick($pick);

        $this->service->evaluatePicks($game);

        $this->assertFalse($pick->isWinner());
    }

    public function testPushIsLoss(): void
    {
        $duke = $this->makeTeam('Duke', 1);
        $norfolk = $this->makeTeam('Norfolk St', 16);

        // Duke wins 80-65, spread Duke -15 -> exact push (margin 15 == 15)
        $game = $this->makeCompletedGame($duke, $norfolk, 80, 65, $duke, 15.0);

        $pick = new Pick();
        $pick->setPlayer(1);
        $pick->setTeam($duke);
        $game->addPick($pick);

        $this->service->evaluatePicks($game);

        $this->assertFalse($pick->isWinner());
    }

    public function testNoSpreadUsesOutrightWinner(): void
    {
        $duke = $this->makeTeam('Duke', 1);
        $norfolk = $this->makeTeam('Norfolk St', 16);

        // No spread set, Duke wins outright
        $game = $this->makeCompletedGame($duke, $norfolk, 82, 65);

        $pickWinner = new Pick();
        $pickWinner->setPlayer(1);
        $pickWinner->setTeam($duke);
        $game->addPick($pickWinner);

        $pickLoser = new Pick();
        $pickLoser->setPlayer(2);
        $pickLoser->setTeam($norfolk);
        $game->addPick($pickLoser);

        $this->service->evaluatePicks($game);

        $this->assertTrue($pickWinner->isWinner());
        $this->assertFalse($pickLoser->isWinner());
    }

    public function testAlreadyEvaluatedPicksAreSkipped(): void
    {
        $duke = $this->makeTeam('Duke', 1);
        $norfolk = $this->makeTeam('Norfolk St', 16);

        $game = $this->makeCompletedGame($duke, $norfolk, 82, 65);

        $pick = new Pick();
        $pick->setPlayer(1);
        $pick->setTeam($duke);
        $pick->setIsWinner(false); // Already evaluated (incorrectly)
        $game->addPick($pick);

        $this->service->evaluatePicks($game);

        // Should not be re-evaluated
        $this->assertFalse($pick->isWinner());
    }

    public function testIncompleteGameIsNotEvaluated(): void
    {
        $duke = $this->makeTeam('Duke', 1);
        $norfolk = $this->makeTeam('Norfolk St', 16);

        $game = new Game();
        $game->setRoundNumber(1);
        $game->setBracketPosition(1);
        $game->setTeam1($duke);
        $game->setTeam2($norfolk);
        $game->setIsComplete(false);

        $pick = new Pick();
        $pick->setPlayer(1);
        $pick->setTeam($duke);
        $game->addPick($pick);

        $this->service->evaluatePicks($game);

        $this->assertNull($pick->isWinner());
    }

    public function testBothPlayersEvaluated(): void
    {
        $duke = $this->makeTeam('Duke', 1);
        $norfolk = $this->makeTeam('Norfolk St', 16);

        // Duke wins 82-65, spread Duke -18.5 -> Norfolk covers
        $game = $this->makeCompletedGame($duke, $norfolk, 82, 65, $duke, 18.5);

        $pick1 = new Pick();
        $pick1->setPlayer(1);
        $pick1->setTeam($duke); // Picked favorite, doesn't cover
        $game->addPick($pick1);

        $pick2 = new Pick();
        $pick2->setPlayer(2);
        $pick2->setTeam($norfolk); // Picked underdog, covers
        $game->addPick($pick2);

        $this->service->evaluatePicks($game);

        $this->assertFalse($pick1->isWinner());
        $this->assertTrue($pick2->isWinner());
    }

    public function testSpreadWithTeam2AsFavorite(): void
    {
        $teamA = $this->makeTeam('Team A', 8);
        $teamB = $this->makeTeam('Team B', 1);

        // Team B is favorite (team2), wins 75-60, spread Team B -10.5
        $game = $this->makeCompletedGame($teamA, $teamB, 60, 75, $teamB, 10.5);

        $pick = new Pick();
        $pick->setPlayer(1);
        $pick->setTeam($teamB); // Picked favorite
        $game->addPick($pick);

        $this->service->evaluatePicks($game);

        // Team B won by 15, spread was 10.5 -> covers
        $this->assertTrue($pick->isWinner());
    }

    public function testAdvanceWinnerOddPosition(): void
    {
        $duke = $this->makeTeam('Duke', 1);
        $nextGame = new Game();
        $nextGame->setRoundNumber(2);
        $nextGame->setBracketPosition(1);

        $game = new Game();
        $game->setRoundNumber(1);
        $game->setBracketPosition(1); // odd -> feeds to team1
        $game->setTeam1($duke);
        $game->setIsComplete(true);
        $game->setWinner($duke);
        $game->setNextGame($nextGame);

        $this->service->advanceWinner($game);

        $this->assertSame($duke, $nextGame->getTeam1());
        $this->assertNull($nextGame->getTeam2());
    }

    public function testAdvanceWinnerEvenPosition(): void
    {
        $norfolk = $this->makeTeam('Norfolk St', 16);
        $nextGame = new Game();
        $nextGame->setRoundNumber(2);
        $nextGame->setBracketPosition(1);

        $game = new Game();
        $game->setRoundNumber(1);
        $game->setBracketPosition(2); // even -> feeds to team2
        $game->setTeam1($norfolk);
        $game->setIsComplete(true);
        $game->setWinner($norfolk);
        $game->setNextGame($nextGame);

        $this->service->advanceWinner($game);

        $this->assertNull($nextGame->getTeam1());
        $this->assertSame($norfolk, $nextGame->getTeam2());
    }

    public function testAdvanceWinnerNoNextGame(): void
    {
        $duke = $this->makeTeam('Duke', 1);

        $game = new Game();
        $game->setRoundNumber(6); // Championship, no next game
        $game->setBracketPosition(1);
        $game->setIsComplete(true);
        $game->setWinner($duke);

        // Should not throw
        $this->service->advanceWinner($game);
        $this->assertTrue(true);
    }
}
