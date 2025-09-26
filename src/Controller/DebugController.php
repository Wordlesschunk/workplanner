<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEvent;
use App\Entity\ICSCalendarEvent;
use App\Repository\TaskRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DebugController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/debug', name: 'app_debug')]
    public function index(): Response
    {


        return $this->render('debug/index.html.twig');
    }

    #[Route('/debug/item', name: 'app_item')]
    public function item(TaskRepository $taskRepository): Response
    {
        $date = new \DateTimeImmutable('now');
        $startOfWeek = $date->modify('monday this week')->setTime(0, 0, 0);
        $endOfWeek = $date->modify('sunday this week')->setTime(23, 59, 59);

        // Query CalendarEvent
        $calendarEvents = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(CalendarEvent::class, 'e')
            ->where('e.startDateTime < :endOfWeek')
            ->andWhere('e.endDateTime >= :startOfWeek')
            ->setParameter('startOfWeek', $startOfWeek)
            ->setParameter('endOfWeek', $endOfWeek)
            ->orderBy('e.startDateTime', 'ASC')
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        // Query ICSCalendarEvent
        $icsEvents = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(ICSCalendarEvent::class, 'e')
            ->where('e.startDateTime < :endOfWeek')
            ->andWhere('e.endDateTime >= :startOfWeek')
            ->setParameter('startOfWeek', $startOfWeek)
            ->setParameter('endOfWeek', $endOfWeek)
            ->orderBy('e.startDateTime', 'ASC')
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        // Merge both arrays
        $allEvents = array_merge($calendarEvents, $icsEvents);

        // Optional: sort again in PHP to guarantee correct ordering
        usort($allEvents, fn ($a, $b) => $a['startDateTime'] <=> $b['startDateTime']
        );

        dd($this->json($allEvents));

        $calendarItemsToday = $ICSCalendarEventRepository->findAllICSEventsInDay(new \DateTimeImmutable('now'));

        dd($calendarItemsToday);
    }
}
