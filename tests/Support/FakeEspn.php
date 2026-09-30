<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Stands in for ESPN's scoreboard and summary endpoints in tests
 * (framework.http_client.mock_response_factory). State is static because the
 * test client reboots the kernel between requests, discarding instances.
 *
 * Mirrors ESPN's real behaviour observed 2026-09-30: `dates` must be YYYYMM or
 * YYYYMMDD; a range (YYYYMMDD-YYYYMMDD) is answered with HTTP 400.
 */
final class FakeEspn
{
    private const SEED_MATCHUPS = [[1, 16], [8, 9], [5, 12], [4, 13], [6, 11], [3, 14], [7, 10], [2, 15]];

    /** @var array<string, list<array>> events keyed by YYYYMM */
    public static array $months = [];
    /** @var array<string, array> summary payloads keyed by event id */
    public static array $summaries = [];
    /** @var list<string> */
    public static array $requests = [];
    public static bool $failAll = false;

    public static function reset(): void
    {
        self::$months = [];
        self::$summaries = [];
        self::$requests = [];
        self::$failAll = false;
    }

    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        self::$requests[] = $url;
        if (self::$failAll) {
            return new MockResponse('{"code":500}', ['http_code' => 500]);
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (str_ends_with($path, '/scoreboard')) {
            $dates = (string) ($query['dates'] ?? '');
            if (!preg_match('/^\d{6}(\d{2})?$/', $dates)) {
                return new MockResponse('{"code":400,"message":"Failed to get events endpoint."}', ['http_code' => 400]);
            }
            return new MockResponse(json_encode(['events' => self::$months[substr($dates, 0, 6)] ?? []]));
        }

        if (str_ends_with($path, '/summary')) {
            $id = (string) ($query['event'] ?? '');
            return isset(self::$summaries[$id])
                ? new MockResponse(json_encode(self::$summaries[$id]))
                : new MockResponse('{}', ['http_code' => 404]);
        }

        return new MockResponse('', ['http_code' => 404]);
    }

    public static function addEvent(int $year, int $month, array $event): void
    {
        self::$months[sprintf('%d%02d', $year, $month)][] = $event;
    }

    public static function firstRound(int $year, array $placeholders = []): void
    {
        foreach (['East', 'West', 'South', 'Midwest'] as $region) {
            foreach (self::SEED_MATCHUPS as [$a, $b]) {
                $side = static fn (int $seed): array => in_array("{$region}_{$seed}", $placeholders, true)
                    ? ['name' => 'TBD', 'seed' => null, 'score' => null]
                    : ['name' => "$region $seed", 'seed' => $seed, 'score' => null];

                self::addEvent($year, 3, self::event(
                    "$year-$region-$a-$b",
                    "NCAA Men's Basketball Championship - $region Region - 1st Round",
                    $side($a),
                    $side($b),
                ));
            }
        }
    }

    public static function event(string $id, string $headline, array $home, array $away, bool $completed = false): array
    {
        $competitor = static fn (array $side, string $homeAway): array => array_filter([
            'homeAway' => $homeAway,
            'team' => ['displayName' => $side['name']],
            'curatedRank' => $side['seed'] !== null ? ['current' => $side['seed']] : null,
            'score' => $side['score'] !== null ? (string) $side['score'] : null,
        ], static fn ($v) => $v !== null);

        return [
            'id' => $id,
            'competitions' => [[
                'notes' => [['headline' => $headline]],
                'competitors' => [$competitor($home, 'home'), $competitor($away, 'away')],
                'status' => ['type' => ['completed' => $completed]],
            ]],
        ];
    }

    public static function setSummary(string $eventId, string $homeName, string $awayName, ?float $spread, bool $homeFavored = true): void
    {
        self::$summaries[$eventId] = [
            'header' => ['competitions' => [['competitors' => [
                ['homeAway' => 'home', 'team' => ['displayName' => $homeName]],
                ['homeAway' => 'away', 'team' => ['displayName' => $awayName]],
            ]]]],
            'pickcenter' => $spread === null ? [] : [[
                'spread' => $homeFavored ? -abs($spread) : abs($spread),
                'homeTeamOdds' => ['favorite' => $homeFavored],
                'awayTeamOdds' => ['favorite' => !$homeFavored],
            ]],
        ];
    }
}
