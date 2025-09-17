<?php

declare(strict_types=1);

namespace App\Controller;

use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DebugController extends AbstractController
{
    #[Route('/debug', name: 'app_debug')]
    public function index(
    ): Response
    {
        return $this->render('debug/index.html.twig');
    }
}
