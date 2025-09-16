<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Engine\SchedulerService;
use App\Entity\CalendarEvent;
use CalendarBundle\Entity\Event;
use CalendarBundle\Event\SetDataEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
class CalendarSubscriber implements EventSubscriberInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public static function getSubscribedEvents()
    {
        return [
            SetDataEvent::class => 'onCalendarSetData',
        ];
    }

    public function onCalendarSetData(SetDataEvent $setDataEvent): void
    {
        $start = $setDataEvent->getStart();
        $end = $setDataEvent->getEnd();
        $filters = $setDataEvent->getFilters();

        $setDataEvent->addEvent(new Event(
            'Event 1',
            new \DateTime('today 5am'),
            new \DateTime('today 9am')
        ));

        $fcEvent = new Event(
            'FooBar',
            new \DateTime('today 11am'),
            new \DateTime('today 1pm'),
        );

        $fcEvent->addOption('rrule', [
            'freq' => 'weekly',
            'interval' => 1,
            'byweekday' => ['MO', 'WE'],
            'dtstart' => '2025-09-01T10:00:00Z',
//            'until'   => $event->getEndTime()->modify('+2 months')->format('Y-m-d\TH:i:s'),
        ]);

        $setDataEvent->addEvent($fcEvent);

    }
}