<?php

namespace App\Tests\Functional;

use App\Entity\Bracket;
use App\Entity\Game;
use App\Entity\Pick;
use App\Entity\Team;
use App\Repository\GameRepository;
use App\Service\BracketBuilderService;
use App\Service\EspnApiService;
use App\Tests\Support\FakeEspn;

class PullTeamsTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeEspn::reset();
    }

    /** A bracket built while East's 16 seed was still undecided. */
    private function bracketWithOpenSlot(string $prefix): Bracket
    {
        FakeEspn::firstRound(2027, ['East_16']);
        $bracket = $this->createBracket($this->createUser("{$prefix}_p1"), $this->createUser("{$prefix}_p2"));
        $bracket->setYear(2027);
        static::getContainer()->get(BracketBuilderService::class)->buildBracket($bracket);
        static::getContainer()->get(EspnApiService::class)->populateBracketTeams($bracket);
        return $bracket;
    }

    private function eastOneGame(Bracket $bracket): Game
    {
        return $this->em->getRepository(Game::class)->findOneBy(
            ['bracket' => $bracket, 'roundNumber' => 1, 'region' => 'East', 'bracketPosition' => 1],
        );
    }

    public function testPlaceholderCompetitorsAreNotSavedAsTeams(): void
    {
        $bracket = $this->bracketWithOpenSlot('pt_tbd');

        $this->assertNull($this->eastOneGame($bracket)->getTeam2());
        $this->assertNull($this->em->getRepository(Team::class)->findOneBy(['name' => 'TBD']));
        $this->assertSame(1, static::getContainer()->get(GameRepository::class)->countMissingFirstRoundTeams($bracket));
    }

    public function testPullTeamsFillsTheSlotOnceDecided(): void
    {
        $bracket = $this->bracketWithOpenSlot('pt_fill');
        $bracketId = $bracket->getId();
        $this->loginViaForm('pt_fill_p1');
        $this->client->request('GET', "/brackets/$bracketId");
        $this->assertSelectorTextContains('#btn-pull-teams', 'Load missing teams (1)');

        FakeEspn::reset();
        FakeEspn::firstRound(2027);
        $this->client->request('POST', "/api/brackets/$bracketId/pull-teams", ['_token' => $this->csrfToken()]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, json_decode($this->client->getResponse()->getContent(), true)['missing']);

        $game = $this->em->getRepository(Game::class)->findOneBy(
            ['bracket' => $bracketId, 'roundNumber' => 1, 'region' => 'East', 'bracketPosition' => 1],
        );
        $this->assertSame('East 16', $game->getTeam2()->getName());
    }

    public function testRefreshKeepsExistingPicks(): void
    {
        $bracket = $this->bracketWithOpenSlot('pt_keep');
        $game = $this->em->getRepository(Game::class)->findOneBy(
            ['bracket' => $bracket, 'roundNumber' => 1, 'region' => 'East', 'bracketPosition' => 2],
        );
        $pickedTeamId = $game->getTeam1()->getId();
        $pick = (new Pick())->setPlayer(1)->setTeam($game->getTeam1());
        $game->addPick($pick);
        $this->em->persist($pick);
        $this->em->flush();

        FakeEspn::reset();
        FakeEspn::firstRound(2027);
        $espn = static::getContainer()->get(EspnApiService::class);
        $espn->reset(); // Same container as bracketWithOpenSlot(); drop its memoised events.
        $espn->populateBracketTeams($bracket);

        $this->assertSame($pickedTeamId, $game->getTeam1()->getId());
        $this->assertSame($pickedTeamId, $game->getPickForPlayer(1)->getTeam()->getId());
    }

    public function testOutsiderCannotPullTeams(): void
    {
        $bracket = $this->bracketWithOpenSlot('pt_out');
        $this->createUser('pt_out_stranger');
        $this->loginViaForm('pt_out_stranger');
        $token = $this->csrfToken();

        $this->client->catchExceptions(false);
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->client->request('POST', "/api/brackets/{$bracket->getId()}/pull-teams", ['_token' => $token]);
    }
}
