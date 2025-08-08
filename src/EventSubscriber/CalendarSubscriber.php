<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use CalendarBundle\Entity\Event;
use CalendarBundle\Event\SetDataEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
class CalendarSubscriber implements EventSubscriberInterface
{
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

        // You may want to make a custom query from your database to fill the calendar

        $setDataEvent->addEvent(new Event(
            'Event 1',
            new \DateTime('Tuesday this week'),
            new \DateTime('Wednesdays this week')
        ));

        // If the end date is null or not defined, it creates a all day event
        $setDataEvent->addEvent(new Event(
            'All day',
            new \DateTime('Friday this week')
        ));
    }
}