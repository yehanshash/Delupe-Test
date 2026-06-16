<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the single-page Vue application shell. All data is loaded by the SPA
 * from the same-origin BFF endpoints under /app-api.
 */
class ShellController extends AbstractController
{
    #[Route('/', name: 'app_shell', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('app.html.twig');
    }
}
