<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CalendarEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'tblCalendarEvent')]
#[ORM\Entity(repositoryClass: CalendarEventRepository::class)]
class CalendarEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column]
    private string $title;

    #[ORM\Column]
    private \DateTime $startTime;

    #[ORM\Column]
    private \DateTime $endTime;

    #[ORM\Column]
    private bool $locked = false; // If locked, it either came from an external calendar or user doesn't want the event to move

    private function __construct(
        string $title,
        \DateTime $startTime,
        \DateTime $endTime,
    ) {
        $this->title = $title;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
    }

    public static function create(
        string $title,
        \DateTime $startTime,
        \DateTime $endTime,
    ): self {
        return new self($title, $startTime, $endTime);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStartTime(): \DateTime
    {
        return $this->startTime;
    }

    public function getEndTime(): \DateTime
    {
        return $this->endTime;
    }

    public function isLocked(): ?bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): static
    {
        $this->locked = $locked;

        return $this;
    }
}
