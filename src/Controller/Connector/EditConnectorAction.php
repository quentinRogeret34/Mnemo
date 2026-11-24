<?php

namespace App\Controller\Connector;

use App\Connector\ConnectorPresets;
use App\Connector\CredentialResolver;
use App\Connector\McpClientFactory;
use App\Entity\Connector;
use App\Enum\ConnectorTransport;
use App\Form\ConnectorType;
use App\Security\SafeUrl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/connectors/new', name: 'connector_new', methods: ['GET', 'POST'])]
#[Route('/connectors/{id}/edit', name: 'connector_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
final class EditConnectorAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectorPresets $presets,
        private readonly McpClientFactory $clientFactory,
        private readonly CredentialResolver $credentials,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, ?Connector $connector = null, #[MapQueryParameter] ?string $preset = null): Response
    {
        $presetData = null === $connector && null !== $preset ? ($this->presets->get($preset) ?? throw $this->createNotFoundException(\sprintf('Unknown preset "%s".', $preset))) : null;
        if (null === $connector) {
            $connector = new Connector();
            if (null !== $presetData) {
                $this->presets->apply($preset, $connector);
            }
        }
        $previousCredentials = $connector->getCredentials();
        $previousOrigin = SafeUrl::origin($connector->getEndpoint());
        $form = $this->createForm(ConnectorType::class, $connector);
        $form->handleRequest($request);

        if ($form->isSubmitted() && ConnectorTransport::Stdio === $connector->getTransport() && !$this->clientFactory->stdioAllowed()) {
            $form->get('transport')->addError(new FormError($this->translator->trans('connector.stdio_disabled')));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $originChanged = null !== $previousOrigin && $previousOrigin !== SafeUrl::origin($connector->getEndpoint());
            $connector->setCredentials($this->credentials->seal($connector->getCredentials(), $originChanged ? [] : $previousCredentials));
            $this->entityManager->persist($connector);
            $this->entityManager->flush();

            return $this->redirectToRoute('connector_show', ['id' => $connector->getId()]);
        }

        return $this->render('connector/edit.html.twig', ['connector' => $connector, 'form' => $form, 'preset' => $presetData, 'preset_key' => $preset], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
