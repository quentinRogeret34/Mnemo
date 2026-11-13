<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppearanceController extends AbstractController
{
    #[Route('/settings/appearance', name: 'appearance', methods: ['GET'])]
    public function show(): Response
    {
        return $this->render('settings/appearance.html.twig');
    }
}
