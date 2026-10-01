<?php

namespace App\Command;

use App\Repository\BracketRepository;
use App\Repository\GameRepository;
use App\Service\EspnApiService;
use App\Service\ScoringService;
use App\Service\TournamentCalendar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:tournament:sync',
    description: 'Pull missing teams, open-round spreads and final scores from ESPN for every bracket of the current tournament',
)]
class TournamentSyncCommand extends Command
{
    public function __construct(
        private TournamentCalendar $calendar,
        private BracketRepository $bracketRepository,
        private GameRepository $gameRepository,
        private EspnApiService $espn,
        private ScoringService $scoring,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('year', null, InputOption::VALUE_REQUIRED, 'Tournament year (default: the current one)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $year = (int) ($input->getOption('year') ?? $this->calendar->activeYear());
        $brackets = $this->bracketRepository->findBy(['year' => $year]);

        if ($brackets === []) {
            $output->writeln("No $year brackets.", OutputInterface::VERBOSITY_VERBOSE);
            return Command::SUCCESS;
        }

        foreach ($brackets as $bracket) {
            if ($this->gameRepository->countMissingFirstRoundTeams($bracket) > 0) {
                $this->espn->populateBracketTeams($bracket);
            }

            $updated = 0;
            for ($round = 1; $round <= 6; $round++) {
                $this->espn->pullSpreads($bracket, $round);
                $updated += $this->espn->updateScores($bracket, $round)['updated'];
                $this->scoring->settleRound($bracket, $round);
            }

            $output->writeln(sprintf('%s (#%d): %d game(s) finalised', $bracket->getName(), $bracket->getId(), $updated));
        }

        return Command::SUCCESS;
    }
}
