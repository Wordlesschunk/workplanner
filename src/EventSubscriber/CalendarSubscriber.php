<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ICSCalendarEvent;
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
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SetDataEvent::class => 'onCalendarSetData',
        ];
    }

    public function onCalendarSetData(SetDataEvent $setDataEvent): void
    {
        $icsCalendarEvents = $this->entityManager
            ->getRepository(ICSCalendarEvent::class)
            ->findAll();

        foreach ($icsCalendarEvents as $icsEvent) {
            $event = $this->createCalendarEvent($icsEvent, false);
            $setDataEvent->addEvent($event);
        }
    }

    private function computeDuration(\DateTimeInterface $start, \DateTimeInterface $end): string
    {
        return $start->diff($end)->format(self::DURATION_FORMAT);
    }

    private function createCalendarEvent(ICSCalendarEvent $source, bool $editableEvent): Event
    {
        $start = $source->getStartDateTime();
        $end = $source->getEndDateTime();

        $event = new Event($source->getTitle(), $start, $end);
        $event->addOption('duration', $this->computeDuration($start, $end));
        $event->addOption('editable', $editableEvent);

        return $event;
    }
}
