<?php

namespace App\Tests\Service;

use App\Repository\SettingRepository;
use App\Service\TournamentCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TournamentCalendarTest extends TestCase
{
    private array $store = [];

    private function calendar(): TournamentCalendar
    {
        $settings = $this->createStub(SettingRepository::class);
        $settings->method('get')->willReturnCallback(fn (string $k, string $d = '') => $this->store[$k] ?? $d);
        $settings->method('set')->willReturnCallback(function (string $k, string $v): void { $this->store[$k] = $v; });
        return new TournamentCalendar($settings);
    }

    public static function dates(): array
    {
        return [
            'January'  => ['2027-01-15', 2027],
            'March'    => ['2027-03-20', 2027],
            'April'    => ['2027-04-30', 2027],
            'May'      => ['2027-05-01', 2028],
            'this fall' => ['2026-09-30', 2027],
            'December' => ['2026-12-31', 2027],
        ];
    }

    #[DataProvider('dates')]
    public function testActiveYearIsTheYearOfTheNextMarch(string $date, int $expected): void
    {
        $this->assertSame($expected, $this->calendar()->activeYear(new \DateTimeImmutable($date)));
    }

    public function testPairsAreNullUntilSet(): void
    {
        $this->assertNull($this->calendar()->finalFourPairs(2027));
    }

    public function testPairsFollowTheEastOpponent(): void
    {
        $calendar = $this->calendar();
        $calendar->setEastOpponent(2027, 'Midwest');
        $this->assertSame([['East', 'Midwest'], ['West', 'South']], $calendar->finalFourPairs(2027));
        $this->assertNull($calendar->finalFourPairs(2028), 'Pairing is per year');
    }

    public function testRejectsUnknownRegion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calendar()->setEastOpponent(2027, 'East');
    }
}
