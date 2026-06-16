<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Swagger UI for the public REST API. The page and the OpenAPI spec
 * (/openapi.yaml, served statically) are public; the documented /api/*
 * endpoints still require the X-API-Key header (use the Authorize button).
 */
class DocsController extends AbstractController
{
    #[Route('/docs', name: 'app_docs', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('docs.html.twig');
    }
}
