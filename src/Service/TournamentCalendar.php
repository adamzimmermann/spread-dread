<?php

namespace App\Service;

use App\Repository\SettingRepository;

/**
 * Which NCAA tournament is current, and how its regions pair in the Final Four.
 * Users never choose a year: it is always the year of the next March.
 */
class TournamentCalendar
{
    public const DEFAULT_PAIRS = [['East', 'West'], ['South', 'Midwest']];
    private const EAST_OPPONENTS = ['West', 'South', 'Midwest'];

    public function __construct(private SettingRepository $settings)
    {
    }

    public function activeYear(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $year = (int) $now->format('Y');

        // The tournament ends in early April; from May on, the next one is current.
        return (int) $now->format('n') >= 5 ? $year + 1 : $year;
    }

    public function eastOpponent(int $year): ?string
    {
        $value = $this->settings->get(self::key($year));
        return in_array($value, self::EAST_OPPONENTS, true) ? $value : null;
    }

    public function setEastOpponent(int $year, string $region): void
    {
        if (!in_array($region, self::EAST_OPPONENTS, true)) {
            throw new \InvalidArgumentException("East cannot play '$region' in the Final Four.");
        }
        $this->settings->set(self::key($year), $region);
    }

    /** @return array{0: array{0: string, 1: string}, 1: array{0: string, 1: string}}|null */
    public function finalFourPairs(int $year): ?array
    {
        $east = $this->eastOpponent($year);
        if ($east === null) {
            return null;
        }
        $others = array_values(array_diff(self::EAST_OPPONENTS, [$east]));
        return [['East', $east], [$others[0], $others[1]]];
    }

    private static function key(int $year): string
    {
        return 'final_four_east_opponent_' . $year;
    }
}
