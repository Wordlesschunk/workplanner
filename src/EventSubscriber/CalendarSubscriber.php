<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Engine\SchedulerService;
use App\Entity\CalendarEvent;
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
        $calendarPath = __DIR__ . '/../calendar.ics';

        if (!file_exists($calendarPath)) {
            return; // Optionally log missing file
        }

        $vcalendar = Reader::read(file_get_contents($calendarPath));

        $startWindow = $setDataEvent->getStart();
        $endWindow = $setDataEvent->getEnd();

        foreach ($vcalendar->VEVENT as $vevent) {
            // Handle recurring events
            if (isset($vevent->RRULE)) {
                $it = new EventIterator($vcalendar, $vevent->UID);
                $it->fastForward($startWindow);

                while ($it->valid() && $it->getDTStart() <= $endWindow) {
                    $fcEvent = new Event(
                        (string)$vevent->SUMMARY,
                        \DateTime::createFromImmutable($it->getDTStart()),
                        \DateTime::createFromImmutable($it->getDTEnd())
                    );

                    // Optional: add extra info
                    if (isset($vevent->DESCRIPTION)) {
                        $fcEvent->addOption('description', (string)$vevent->DESCRIPTION);
                    }
                    if (isset($vevent->LOCATION)) {
                        $fcEvent->addOption('location', (string)$vevent->LOCATION);
                    }

                    $setDataEvent->addEvent($fcEvent);
                    $it->next();
                }
            } else {
                // Single (non-recurring) events
                $start = $vevent->DTSTART->getDateTime();
                $end = $vevent->DTEND->getDateTime();

                // Only add events within the calendar view window
                if ($start <= $endWindow && $end >= $startWindow) {
                    $fcEvent = new Event(
                        (string)$vevent->SUMMARY,
                        \DateTime::createFromImmutable($start),
                        \DateTime::createFromImmutable($end)
                    );

                    if (isset($vevent->DESCRIPTION)) {
                        $fcEvent->addOption('description', (string)$vevent->DESCRIPTION);
                    }
                    if (isset($vevent->LOCATION)) {
                        $fcEvent->addOption('location', (string)$vevent->LOCATION);
                    }

                    $setDataEvent->addEvent($fcEvent);
                }
            }
        }

    }
}