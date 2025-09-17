<?php

namespace App\Controller;

use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DebugController extends AbstractController
{
    #[Route('/debug', name: 'app_deub')]
    public function index(
    ): Response
    {

//        $foo = 'FREQ=WEEKLY;UNTIL=20241118T140000Z;INTERVAL=1;BYDAY=MO;WKST=MO';
//        $foo = 'FREQ=WEEKLY;UNTIL=20260916T110000Z;INTERVAL=1;BYDAY=MO,TU,WE,TH,FR;WKST=SU';
//        $parts = array_reduce(explode(';', $foo), function ($carry, $item) {
//            list($key, $value) = explode('=', $item);
//            $carry[strtolower($key)] = $value;
//            return $carry;
//        }, []);
//
//        dump($parts);

        $calendarPath = __DIR__ . '/../calendar.ics';

        if (!file_exists($calendarPath)) {
            throw new \InvalidArgumentException("File not found: {$calendarPath}");
        }

        $events = [];
        $vcalendar = Reader::read(file_get_contents($calendarPath));

        $startWindow = new \DateTimeImmutable('-1 week');
        $endWindow = new \DateTimeImmutable('+3 weeks');

        foreach ($vcalendar->VEVENT as $vevent) {



            $start = $vevent->DTSTART->getDateTime();

            if (isset($vevent->RRULE)) {

                dump($vevent);

                dd($vevent->RRULE->getValue());

                // Recurring event
                $it = new EventIterator($vcalendar, $vevent->UID);

                // Optional: ignore the RRULE UNTIL date
                $it->fastForward($startWindow);

                // Generate occurrences up to $endWindow
                while ($it->valid()) {
                    $occurrenceStart = $it->getDTStart();

                    if ($occurrenceStart > $endWindow) {
                        break;
                    }

                    if ($occurrenceStart >= $startWindow) {
                        $events[] = [
                            'summary' => (string) $vevent->SUMMARY,
                            'description' => isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
                            'start' => $occurrenceStart,
                            'end' => $it->getDTEnd(),
                            'location' => isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
                            'is_recurring' => true,
                            'recurrence_rule' => (string) $vevent->RRULE,
                        ];
                    }

                    $it->next();
                }
            } else {
                // Single (non-recurring) events
                if ($start >= $startWindow && $start <= $endWindow) {
                    $events[] = [
                        'summary' => (string) $vevent->SUMMARY,
                        'description' => isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
                        'start' => $start,
                        'end' => $vevent->DTEND->getDateTime(),
                        'location' => isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
                        'is_recurring' => false,
                        'recurrence_rule' => null,
                    ];
                }
            }
        }

        // Optional: sort events by start date
        usort($events, fn($a, $b) => $a['start'] <=> $b['start']);

        dd($events);

        return $this->render('deub/index.html.twig', [
            'controller_name' => 'DeubController',
        ]);
    }


    #[Route('/json', name: 'app_deusb')]
    public function jsosn(
    ): Response
    {
        $calendarPath = __DIR__ . '/../calendar.ics';

        if (!file_exists($calendarPath)) {
            return $this->json(['error' => 'File not found'], 404);
        }

        $events = [];
        $vcalendar = Reader::read(file_get_contents($calendarPath));

        $startWindow = new \DateTimeImmutable('-1 week');
        $endWindow = new \DateTimeImmutable('+3 weeks');

        foreach ($vcalendar->VEVENT as $vevent) {
            if (isset($vevent->RRULE)) {
                $it = new EventIterator($vcalendar, $vevent->UID);
                $it->fastForward($startWindow);

                while ($it->valid()) {
                    $occurrenceStart = $it->getDTStart();
                    if ($occurrenceStart > $endWindow) break;

                    if ($occurrenceStart >= $startWindow) {
                        $events[] = [
                            'title' => (string) $vevent->SUMMARY,
                            'start' => $occurrenceStart->format('c'),
                            'end' => $it->getDTEnd()->format('c'),
                            'description' => isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
                            'location' => isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
                        ];
                    }

                    $it->next();
                }
            } else {
                $start = $vevent->DTSTART->getDateTime();
                if ($start >= $startWindow && $start <= $endWindow) {
                    $events[] = [
                        'title' => (string) $vevent->SUMMARY,
                        'start' => $start->format('c'),
                        'end' => $vevent->DTEND->getDateTime()->format('c'),
                        'description' => isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
                        'location' => isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
                    ];
                }
            }
        }

        // Sort by start date
        usort($events, fn($a, $b) => strtotime($a['start']) <=> strtotime($b['start']));

        dd ($this->json($events));

        return $this->render('deub/index.html.twig', [
            'controller_name' => 'DeubController',
        ]);
    }
}
