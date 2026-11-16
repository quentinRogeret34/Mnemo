<?php

namespace App\Controller;

use App\Locale\SessionLocaleListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

final class AppearanceController extends AbstractController
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
    ) {
    }

    #[Route('/settings/appearance', name: 'appearance', methods: ['GET'])]
    public function show(): Response
    {
        return $this->render('settings/appearance.html.twig', ['locales' => $this->enabledLocales]);
    }

    #[Route('/settings/locale', name: 'locale', methods: ['POST'])]
    #[IsCsrfTokenValid('locale')]
    public function locale(Request $request): RedirectResponse
    {
        $locale = $request->request->getString('locale');
        if (\in_array($locale, $this->enabledLocales, true)) {
            $request->getSession()->set(SessionLocaleListener::SESSION_KEY, $locale);
        }

        return $this->redirectToRoute('appearance');
    }
}
