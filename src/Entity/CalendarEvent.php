<?php

declare(strict_types=1);

namespace App\Entity;

use App\Interface\CalendarEventInterface;
use App\Repository\CalendarEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'tblCalendarEvent')]
#[ORM\Entity(repositoryClass: CalendarEventRepository::class)]
class CalendarEvent implements CalendarEventInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column]
    private string $title;

    #[ORM\Column(length: 2550)]
    private string $description;

    #[ORM\Column]
    private \DateTime $startDateTime;

    #[ORM\Column]
    private \DateTime $endDateTime;

    #[ORM\Column]
    private bool $locked = false;

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getStartDateTime(): \DateTime
    {
        return $this->startDateTime;
    }

    public function setStartDateTime(\DateTime $startDateTime): void
    {
        $this->startDateTime = $startDateTime;
    }

    public function getEndDateTime(): \DateTime
    {
        return $this->endDateTime;
    }

    public function setEndDateTime(\DateTime $endDateTime): void
    {
        $this->endDateTime = $endDateTime;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): void
    {
        $this->locked = $locked;
    }
}
