<?php

namespace App\Controller;

use App\Engine\SchedulerService;
use App\Engine\TimeChunkEngine;
use App\Entity\CalendarEvent;
use App\Entity\Task;
use CalendarBundle\Entity\Event;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DebugController extends AbstractController
{
    #[Route('/debug', name: 'app_deub')]
    public function index(
        EntityManagerInterface $entityManager,
        TimeChunkEngine $timeChunkEngine,
    ): Response
    {
        $tasks = [
            ['title' => 'Task 1', 'duration' => 7200], // 2 hours
            ['title' => 'Task 2', 'duration' => 3600], // 1 hour
        ];

        $calendarEvents = $entityManager->getRepository(CalendarEvent::class)->findBy(['title' => 'LUNCH']);

        $scheduler = new SchedulerService();
        $scheduler->setBreakMinutes(15);
        $scheduler->setCalendarEvents($calendarEvents);

        $scheduled = $scheduler->scheduleTasks($tasks);

        foreach ($scheduled as $event) {
            echo $event->getTitle() . ' - ' . $event->getStartTime()->format('Y-m-d H:i')
                . ' to ' . $event->getEndTime()->format('H:i') . PHP_EOL;
        }

        die;

















//        $tasks = $entityManager->getRepository(Task::class)->findAll();
//
//        foreach ($tasks as $task) {
//            $chunk = $timeChunkEngine->splitEven($task->getDuration());
//
//            dd($chunk);
//        }
//
//
//
//
//



        return $this->render('deub/index.html.twig', [
            'controller_name' => 'DeubController',
        ]);
    }
}
