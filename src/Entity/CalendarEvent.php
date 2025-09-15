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

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column]
    private \DateTime $startTime;

    #[ORM\Column]
    private \DateTime $endTime;

    /**
     * @param string $title
     * @param \DateTime $startTime
     * @param \DateTime $endTime
     */
    private function __construct(
        string $title,
        \DateTime $startTime,
        \DateTime $endTime
    )
    {
        $this->title = $title;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
    }

    public static function create(
        string $title,
        \DateTime $startTime,
        \DateTime $endTime
    ): self
    {
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
}
