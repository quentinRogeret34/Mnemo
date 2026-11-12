<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WikiController extends AbstractController
{
    #[Route('/wiki', name: 'wiki', methods: ['GET'])]
    public function show(): Response
    {
        return $this->render('wiki/show.html.twig');
    }
}
