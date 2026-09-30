<?php

namespace App\Tests\Functional;

use App\Service\EspnApiService;
use App\Tests\Support\FakeEspn;

class EspnApiServiceTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeEspn::reset();
    }

    private function espn(): EspnApiService
    {
        return static::getContainer()->get(EspnApiService::class);
    }

    public function testTeamsComeFromMonthQueriesNotRanges(): void
    {
        FakeEspn::firstRound(2027);

        $result = $this->espn()->fetchTournamentTeams(2027);

        $this->assertCount(64, $result['teams']);
        $this->assertCount(32, $result['matchups']);
        foreach (FakeEspn::$requests as $url) {
            $this->assertMatchesRegularExpression('/dates=2027(03|04)(&|$)/', $url);
        }
    }

    public function testEventsAreFetchedOncePerService(): void
    {
        FakeEspn::firstRound(2027);
        $espn = $this->espn();

        $espn->fetchTournamentEvents(2027);
        $espn->fetchTournamentEvents(2027);

        $this->assertCount(2, FakeEspn::$requests, 'One request per month, then cached');
    }

    public function testAprilGamesAreIncluded(): void
    {
        FakeEspn::addEvent(2027, 4, FakeEspn::event(
            'final', "NCAA Men's Basketball Championship - National Championship",
            ['name' => 'East 1', 'seed' => 1, 'score' => 70], ['name' => 'West 1', 'seed' => 1, 'score' => 65], true,
        ));

        $ids = array_column($this->espn()->fetchTournamentEvents(2027), 'id');
        $this->assertContains('final', $ids);
    }

    public function testFieldIsSetOnlyWithSixtyTeams(): void
    {
        $this->assertFalse($this->espn()->tournamentFieldIsSet(2027), 'Nothing listed yet');

        FakeEspn::reset();
        $this->espn()->reset(); // The empty result above is memoised.
        FakeEspn::firstRound(2027, ['East_16', 'West_16', 'South_11', 'Midwest_11']);
        $this->assertTrue($this->espn()->tournamentFieldIsSet(2027), 'Four First Four slots still open');
    }

    public function testFailureIsReportedAsNoTeams(): void
    {
        FakeEspn::$failAll = true;
        $result = $this->espn()->fetchTournamentTeams(2027);
        $this->assertSame([], $result['teams']);
        $this->assertArrayHasKey('error', $result);
    }
}
