<?php

declare(strict_types=1);

namespace App\Controller;

use DateTime;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DebugController extends AbstractController
{
    #[Route('/debug', name: 'app_debug')]
    public function index(
    ): Response {
        /**
         * Schedule a single task into the earliest free timeslot that fully fits it.
         *
         * @param array $task           e.g. [
         *                              'id' => 3, 'name' => 'nap', 'required_duration_seconds' => 3600,
         *                              'completed_duration_seconds' => 0, // or 3600 if already done
         *                              ]
         * @param array $timeslots      Each: ['start' => DateTime, 'end' => DateTime, 'duration' => int]
         * @param int   $paddingSeconds Optional padding before & after within the slot (default 0)
         *
         * @return array|null booking or null if no slot fits
         */
        function scheduleTaskIntoTimeslots(array $task, array $timeslots, int $paddingSeconds = 0): ?array
        {
            $required = max(0, (int) ($task['required_duration_seconds'] ?? 0));
            $completed = max(0, (int) ($task['completed_duration_seconds'] ?? 0));
            $remaining = max(0, $required - $completed);

            if (0 === $remaining) {
                // Already complete — nothing to schedule.
                return null;
            }

            // Iterate timeslots in chronological order (assumed sorted).
            foreach ($timeslots as $idx => $slot) {
                /** @var \DateTime $slotStart */
                $slotStart = $slot['start'];
                /** @var \DateTime $slotEnd */
                $slotEnd = $slot['end'];

                // Apply optional padding inside the slot.
                $freeStart = (clone $slotStart)->modify("+{$paddingSeconds} seconds");
                $freeEnd = (clone $slotEnd)->modify("-{$paddingSeconds} seconds");

                // Compute available seconds after padding.
                $available = max(0, $freeEnd->getTimestamp() - $freeStart->getTimestamp());
                if ($available < $remaining) {
                    continue; // too small, try next slot
                }

                // Book from freeStart for exactly $remaining seconds.
                $bookingStart = clone $freeStart;
                $bookingEnd = (clone $bookingStart)->modify("+{$remaining} seconds");

                return [
                    'task_id' => (int) ($task['id'] ?? 0),
                    'name' => (string) ($task['name'] ?? ''),
                    'start' => $bookingStart,      // DateTime
                    'end' => $bookingEnd,        // DateTime
                    'duration' => $remaining,         // seconds
                    'slot_index' => $idx,
                ];
            }

            // No slot could fully fit the remaining duration.
            return null;
        }

        // ---------------------- Example usage ----------------------

        $task = [
            'id' => 3,
            'name' => 'nap',
            'required_duration_seconds' => 3600,
            'completed_duration_seconds' => 0, // set to 3600 if already complete
        ];

        // Your provided timeslots (ensure these are real DateTime objects in your code).
        $timeslots = [
            [
                'start' => new \DateTime('2025-09-25 08:00:00', new DateTimeZone('UTC')),
                'end' => new \DateTime('2025-09-25 09:15:00', new DateTimeZone('UTC')),
                'duration' => 4500,
            ],
            [
                'start' => new \DateTime('2025-09-25 09:30:00', new DateTimeZone('UTC')),
                'end' => new \DateTime('2025-09-25 11:00:00', new DateTimeZone('UTC')),
                'duration' => 5400,
            ],
            [
                'start' => new \DateTime('2025-09-25 11:30:00', new DateTimeZone('UTC')),
                'end' => new \DateTime('2025-09-25 12:00:00', new DateTimeZone('UTC')),
                'duration' => 1800,
            ],
            [
                'start' => new \DateTime('2025-09-25 13:30:00', new DateTimeZone('UTC')),
                'end' => new \DateTime('2025-09-25 14:00:00', new DateTimeZone('UTC')),
                'duration' => 1800,
            ],
            [
                'start' => new \DateTime('2025-09-25 15:00:00', new DateTimeZone('UTC')),
                'end' => new \DateTime('2025-09-25 17:00:00', new DateTimeZone('UTC')),
                'duration' => 7200,
            ],
        ];

        $booking = scheduleTaskIntoTimeslots($task, $timeslots);

        // Pretty-print result
        if ($booking) {
            echo "Scheduled '{$booking['name']}' in slot #{$booking['slot_index']}:\n";
            echo 'Start: '.$booking['start']->format(\DateTime::ATOM)."\n";
            echo 'End:   '.$booking['end']->format(\DateTime::ATOM)."\n";
            echo "Dur:   {$booking['duration']} seconds\n";
        } else {
            echo "No available slot can fit the task.\n";
        }


        die;
        return $this->render('debug/index.html.twig');
    }
}
