<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'tblTask')]
#[ORM\Entity(repositoryClass: TaskRepository::class)]
class Task
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private string $notes;

    #[ORM\Column]
    private string $priority = 'LOW';

    #[ORM\Column]
    private int $requiredDurationSeconds = 0; // Time required to complete task in seconds

    #[ORM\Column]
    private int $completedDurationSeconds = 0; // Time spent on task in seconds

    #[ORM\Column]
    private int $eventMinDuration = 0;

    #[ORM\Column]
    private int $eventMaxDuration = 0;

    #[ORM\Column]
    private \DateTime $scheduleAfter;

    #[ORM\Column]
    private \DateTime $dueDate;

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getNotes(): string
    {
        return $this->notes;
    }

    public function setNotes(string $notes): void
    {
        $this->notes = $notes;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): void
    {
        $this->priority = $priority;
    }

    public function getRequiredDurationSeconds(): int
    {
        return $this->requiredDurationSeconds;
    }

    public function setRequiredDurationSeconds(int $requiredDurationSeconds): void
    {
        $this->requiredDurationSeconds = $requiredDurationSeconds;
    }

    public function getCompletedDurationSeconds(): int
    {
        return $this->completedDurationSeconds;
    }

    public function setCompletedDurationSeconds(int $completedDurationSeconds): void
    {
        $this->completedDurationSeconds = $completedDurationSeconds;
    }

    public function getEventMinDuration(): int
    {
        return $this->eventMinDuration;
    }

    public function setEventMinDuration(int $eventMinDuration): void
    {
        $this->eventMinDuration = $eventMinDuration;
    }

    public function getEventMaxDuration(): int
    {
        return $this->eventMaxDuration;
    }

    public function setEventMaxDuration(int $eventMaxDuration): void
    {
        $this->eventMaxDuration = $eventMaxDuration;
    }

    public function getScheduleAfter(): \DateTime
    {
        return $this->scheduleAfter;
    }

    public function setScheduleAfter(\DateTime $scheduleAfter): void
    {
        $this->scheduleAfter = $scheduleAfter;
    }

    public function getDueDate(): \DateTime
    {
        return $this->dueDate;
    }

    public function setDueDate(\DateTime $dueDate): void
    {
        $this->dueDate = $dueDate;
    }
}
