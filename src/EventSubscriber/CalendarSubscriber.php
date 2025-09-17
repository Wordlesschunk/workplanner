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
        $standardICSCalendarEvents = $this->entityManager->getRepository(CalendarEventICS::class)->findBy(['isRecurring' => 0]);

        dump($recurringICSCalendarEvents);

        /** @var CalendarEventICS $recurringEvent */
//        foreach ($recurringICSCalendarEvents as $recurringEvent) {
//
////            $rruleEvent = new Event(
////                $recurringEvent->getSummary(),
////                new \DateTime($recurringEvent->getStart()->format('Y-m-d h:i')),
////                new \DateTime($recurringEvent->getEnd()->format('Y-m-d h:i')),
////            );
////
////
////            dump($parts);
////
//////            'rrule' => 'DTSTART:' . $recurringEvent->getStart()->format('Ymd\THis\Z') . '\nRRULE:' . $recurringEvent->getRecurringData(),
//////
//////            rrule: 'DTSTART:20120201T103000Z\nRRULE:FREQ=WEEKLY;INTERVAL=5;UNTIL=20120601;BYDAY=MO,FR'
////
////
////            $rruleEvent->addOption('rrule', 'DTSTART:20250201T103000Z\nRRULE:FREQ=WEEKLY;INTERVAL=5;UNTIL=20120601;BYDAY=MO,FR');
//////
//////            $rruleEvent->addOption('rrule', [
//////                'freq' => $parts['freq'],
//////                'interval' => $parts['interval'],
////////                'byweekday' => isset($parts['byday']) ? [$parts['byday']] : null,
//////                'dtstart' => $recurringEvent->getStart()->format('Y-m-d\TH:i:s\Z'),
//////                'until' => isset($parts['until']) ? $parts['until'] : null,
//////            ]);
////
////            $setDataEvent->addEvent($rruleEvent);
//
//            $parts = array_reduce(explode(';', $recurringEvent->getRecurringData()), function ($carry, $item) {
//                list($key, $value) = explode('=', $item);
//                $carry[strtolower($key)] = $value;
//                return $carry;
//            }, []);
//
//            $byWeekDay =  isset($parts['byday']) ? explode(',', $parts['byday']) : null;
//
//            $rruleEvent->addOption('rrule', [
//                'freq' => $parts['freq'],
//                'interval' => $parts['interval'],
////                'byweekday' => $byWeekDay,
////                'start' => new \DateTime('2024-01-01'),
////                'until' => $parts['until'],
//            ]);
////
//            $setDataEvent->addEvent($rruleEvent);
        }


//        $setDataEvent->addEvent(new Event(
//            'Event 1',
//            new \DateTime('today 5am'),
//            new \DateTime('today 9am')
//        ));
//
//        $fcEvent = new Event(
//            'FooBar',
//            new \DateTime('today 11am'),
//            new \DateTime('today 1pm'),
//        );
//
//        $fcEvent->addOption('rrule', [
//            'freq' => 'weekly',
//            'interval' => 1,
//            'byweekday' => ['MO', 'WE'],
//            'dtstart' => '2025-09-01T10:00:00Z',
////            'until'   => $event->getEndTime()->modify('+2 months')->format('Y-m-d\TH:i:s'),
//        ]);
//
//        $setDataEvent->addEvent($fcEvent);

    }



//        $calendarPath = __DIR__ . '/../calendar.ics';
//
//        if (!file_exists($calendarPath)) {
//            return; // Optionally log missing file
//        }
//
//        $vcalendar = Reader::read(file_get_contents($calendarPath));
//
//        $startWindow = $setDataEvent->getStart();
//        $endWindow = $setDataEvent->getEnd();
//
//        foreach ($vcalendar->VEVENT as $vevent) {
//            // Handle recurring events
//            if (isset($vevent->RRULE)) {
//                $it = new EventIterator($vcalendar, $vevent->UID);
//                $it->fastForward($startWindow);
//
//                while ($it->valid() && $it->getDTStart() <= $endWindow) {
//                    $fcEvent = new Event(
//                        (string)$vevent->SUMMARY,
//                        \DateTime::createFromImmutable($it->getDTStart()),
//                        \DateTime::createFromImmutable($it->getDTEnd())
//                    );
//
//                    // Optional: add extra info
//                    if (isset($vevent->DESCRIPTION)) {
//                        $fcEvent->addOption('description', (string)$vevent->DESCRIPTION);
//                    }
//                    if (isset($vevent->LOCATION)) {
//                        $fcEvent->addOption('location', (string)$vevent->LOCATION);
//                    }
//
//                    $setDataEvent->addEvent($fcEvent);
//                    $it->next();
//                }
//            } else {
//                // Single (non-recurring) events
//                $start = $vevent->DTSTART->getDateTime();
//                $end = $vevent->DTEND->getDateTime();
//
//                // Only add events within the calendar view window
//                if ($start <= $endWindow && $end >= $startWindow) {
//                    $fcEvent = new Event(
//                        (string)$vevent->SUMMARY,
//                        \DateTime::createFromImmutable($start),
//                        \DateTime::createFromImmutable($end)
//                    );
//
//                    if (isset($vevent->DESCRIPTION)) {
//                        $fcEvent->addOption('description', (string)$vevent->DESCRIPTION);
//                    }
//                    if (isset($vevent->LOCATION)) {
//                        $fcEvent->addOption('location', (string)$vevent->LOCATION);
//                    }
//
//                    $setDataEvent->addEvent($fcEvent);
//                }
//            }
//        }

}