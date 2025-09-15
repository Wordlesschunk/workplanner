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

        $calendarEvent = $this->entityManager->getRepository(CalendarEvent::class)->findAll();

        dump($calendarEvent);

        foreach ($calendarEvent as $event) {

            $setDataEvent->addEvent(new Event(
                $event->getTitle(),
                new \DateTime($event->getStartTime()->format('Y-m-d h:i:s A')),
                new \DateTime($event->getEndTime()->format('Y-m-d h:i:s A'))
            ));
        }


        $tasks = [
            ['title' => 'Task 1', 'duration' => 7200], // 2 hours
            ['title' => 'Task 2', 'duration' => 19800], // 5.5 hour
            ['title' => 'Task 4', 'duration' => 7200], // 5.5 hour
            ['title' => 'Task 6', 'duration' => 30240], // 5.5 hour
            ['title' => 'Task 3', 'duration' => 19800], // 5.5 hour
        ];

//        $calendarEvents = $this->entityManager->getRepository(CalendarEvent::class)->findBy(['title' => 'LUNCH']);
        $calendarEvents = $this->entityManager->getRepository(CalendarEvent::class)->findAll();

        $scheduler = new SchedulerService();
        $scheduler->setBreakMinutes(15);
        $scheduler->setCalendarEvents($calendarEvents);

        $scheduled = $scheduler->scheduleTasks($tasks);

        foreach ($scheduled as $event) {

            $setDataEvent->addEvent(new Event(
                $event->getTitle(),
                new \DateTime($event->getStartTime()->format('Y-m-d h:i:s A')),
                new \DateTime($event->getEndTime()->format('Y-m-d h:i:s A'))
            ));

//            echo $event->getTitle() . ' - ' . $event->getStartTime()->format('Y-m-d H:i')
//                . ' to ' . $event->getEndTime()->format('H:i') . PHP_EOL;
        }



    }
}