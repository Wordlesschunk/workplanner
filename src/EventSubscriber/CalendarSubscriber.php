<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Engine\SchedulerService;
use App\Entity\CalendarEvent;
use App\Entity\CalendarEventICS;
use CalendarBundle\Entity\Event;
use CalendarBundle\Event\SetDataEvent;
use Doctrine\ORM\EntityManagerInterface;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
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

        $recurringICSCalendarEvents = $this->entityManager->getRepository(CalendarEventICS::class)->findBy(['isRecurring' => 1]);
        /** @var CalendarEventICS $recurringEvent */
        foreach ($recurringICSCalendarEvents as $recurringEvent) {

            $start = $recurringEvent->getStart();
            $end = $recurringEvent->getEnd();
            $diff = $start->diff($end);
            $formattedDiff = $diff->format('%H:%I'); // HH:MM

            $icsEvent = new Event($recurringEvent->getSummary(), $start, $end);

            $rrule = sprintf('%s;DTSTART=%s',
                $recurringEvent->getRecurringData(),
                $start->format('Ymd\THis\Z')
            );

            $icsEvent->addOption('rrule', $rrule);
            $icsEvent->addOption('duration', $formattedDiff);

            $setDataEvent->addEvent($icsEvent);
        }


        $standardICSCalendarEvents = $this->entityManager->getRepository(CalendarEventICS::class)->findBy(['isRecurring' => 0]);
        // todo do logic here

    }
}