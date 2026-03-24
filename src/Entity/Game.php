<?php

namespace App\Entity;

use App\Repository\GameRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameRepository::class)]
class Game
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Bracket::class, inversedBy: 'games')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Bracket $bracket = null;

    #[ORM\Column]
    private int $roundNumber;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $region = null;

    #[ORM\Column]
    private int $bracketPosition;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Team $team1 = null;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Team $team2 = null;

    #[ORM\Column(nullable: true)]
    private ?float $spread = null;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Team $spreadTeam = null;

    #[ORM\Column(nullable: true)]
    private ?int $team1Score = null;

    #[ORM\Column(nullable: true)]
    private ?int $team2Score = null;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Team $winner = null;

    #[ORM\ManyToOne(targetEntity: Game::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Game $nextGame = null;

    #[ORM\Column]
    private bool $isComplete = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalGameId = null;

    /** @var Collection<int, Pick> */
    #[ORM\OneToMany(targetEntity: Pick::class, mappedBy: 'game', cascade: ['persist', 'remove'])]
    private Collection $picks;

    public function __construct()
    {
        $this->picks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBracket(): ?Bracket
    {
        return $this->bracket;
    }

    public function setBracket(?Bracket $bracket): self
    {
        $this->bracket = $bracket;
        return $this;
    }

    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    public function setRoundNumber(int $roundNumber): self
    {
        $this->roundNumber = $roundNumber;
        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): self
    {
        $this->region = $region;
        return $this;
    }

    public function getBracketPosition(): int
    {
        return $this->bracketPosition;
    }

    public function setBracketPosition(int $bracketPosition): self
    {
        $this->bracketPosition = $bracketPosition;
        return $this;
    }

    public function getTeam1(): ?Team
    {
        return $this->team1;
    }

    public function setTeam1(?Team $team1): self
    {
        $this->team1 = $team1;
        return $this;
    }

    public function getTeam2(): ?Team
    {
        return $this->team2;
    }

    public function setTeam2(?Team $team2): self
    {
        $this->team2 = $team2;
        return $this;
    }

    public function getSpread(): ?float
    {
        return $this->spread;
    }

    public function setSpread(?float $spread): self
    {
        $this->spread = $spread;
        return $this;
    }

    public function getSpreadTeam(): ?Team
    {
        return $this->spreadTeam;
    }

    public function setSpreadTeam(?Team $spreadTeam): self
    {
        $this->spreadTeam = $spreadTeam;
        return $this;
    }

    public function getTeam1Score(): ?int
    {
        return $this->team1Score;
    }

    public function setTeam1Score(?int $team1Score): self
    {
        $this->team1Score = $team1Score;
        return $this;
    }

    public function getTeam2Score(): ?int
    {
        return $this->team2Score;
    }

    public function setTeam2Score(?int $team2Score): self
    {
        $this->team2Score = $team2Score;
        return $this;
    }

    public function getWinner(): ?Team
    {
        return $this->winner;
    }

    public function setWinner(?Team $winner): self
    {
        $this->winner = $winner;
        return $this;
    }

    public function getNextGame(): ?Game
    {
        return $this->nextGame;
    }

    public function setNextGame(?Game $nextGame): self
    {
        $this->nextGame = $nextGame;
        return $this;
    }

    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    public function setIsComplete(bool $isComplete): self
    {
        $this->isComplete = $isComplete;
        return $this;
    }

    public function getExternalGameId(): ?string
    {
        return $this->externalGameId;
    }

    public function setExternalGameId(?string $externalGameId): self
    {
        $this->externalGameId = $externalGameId;
        return $this;
    }

    /** @return Collection<int, Pick> */
    public function getPicks(): Collection
    {
        return $this->picks;
    }

    public function addPick(Pick $pick): self
    {
        if (!$this->picks->contains($pick)) {
            $this->picks->add($pick);
            $pick->setGame($this);
        }
        return $this;
    }

    public function getPickForPlayer(int $player): ?Pick
    {
        foreach ($this->picks as $pick) {
            if ($pick->getPlayer() === $player) {
                return $pick;
            }
        }
        return null;
    }

    public function getRoundName(): string
    {
        return Round::getRoundName($this->roundNumber);
    }
}
