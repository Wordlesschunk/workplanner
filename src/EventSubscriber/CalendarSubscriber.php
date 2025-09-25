<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\CalendarEvent;
use App\Entity\ICSCalendarEvent;
use App\Interface\CalendarEventInterface;
use CalendarBundle\Entity\Event;
use CalendarBundle\Event\SetDataEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CalendarSubscriber implements EventSubscriberInterface
{
    private const DURATION_FORMAT = '%H:%I'; // HH:MM
    private const DTSTART_FORMAT = 'Ymd\THis\Z';

    public function __construct(
        private EntityManagerInterface $entityManager,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SetDataEvent::class => 'onCalendarSetData',
        ];
    }

    public function onCalendarSetData(SetDataEvent $setDataEvent): void
    {
        $events = [...$this->entityManager
            ->getRepository(CalendarEvent::class)
            ->findAll(),

            ...$this->entityManager
                ->getRepository(ICSCalendarEvent::class)
                ->findAll(),
        ];

        dump($events);

        foreach ($events as $icsEvent) {
            $event = $this->createCalendarEvent($icsEvent, false);

            $setDataEvent->addEvent($event);
        }
    }

    private function computeDuration(\DateTimeInterface $start, \DateTimeInterface $end): string
    {
        return $start->diff($end)->format(self::DURATION_FORMAT);
    }

    private function createCalendarEvent(CalendarEventInterface $source, bool $editableEvent): Event
    {
        $start = \DateTime::createFromInterface($source->getStartDateTime());
        $end = \DateTime::createFromInterface($source->getEndDateTime());

        $event = new Event($source->getTitle(), $start, $end);
        $event->addOption('duration', $this->computeDuration($start, $end));
        $event->addOption('editable', $editableEvent);

        if ($source instanceof CalendarEvent) {
            $event->addOption('backgroundColor', 'green');
        }

        return $event;
    }
}
