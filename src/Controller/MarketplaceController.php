<?php

namespace App\Controller;

use App\Addon\AddonCategory;
use App\Addon\AddonManager;
use App\Addon\AddonScope;
use App\Addon\Builtin\CodeGraphAddon;
use App\Addon\Builtin\FactoryAddon;
use App\Addon\Builtin\StudioAddon;
use App\Entity\Project;
use App\Security\ProjectScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MarketplaceController extends AbstractController
{
    public function __construct(
        private readonly AddonManager $addonManager,
        private readonly ProjectScope $projectScope,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/marketplace', name: 'marketplace_index', methods: ['GET'])]
    public function index(
        #[MapQueryParameter] ?string $project = null,
        #[MapQueryParameter] ?string $category = null,
    ): Response {
        $projectEntity = $this->projectScope->bySlug($project);
        $categoryEnum = null === $category ? null : AddonCategory::tryFrom($category);

        $allAddons = $this->addonManager->all();
        $categoryCounts = [];
        foreach (AddonCategory::cases() as $case) {
            $categoryCounts[$case->value] = 0;
        }

        $activeCount = 0;
        $enabledById = [];
        foreach ($allAddons as $addon) {
            $manifest = $addon->manifest();
            ++$categoryCounts[$manifest->category->value];
            $enabled = $this->addonManager->isEnabled($manifest->id, $projectEntity);
            $enabledById[$manifest->id] = $enabled;
            if ($enabled) {
                ++$activeCount;
            }
        }

        $cards = [];
        foreach ($this->addonManager->byCategory($categoryEnum) as $addon) {
            $manifest = $addon->manifest();
            $cards[] = [
                'manifest' => $manifest,
                'enabled' => $enabledById[$manifest->id] ?? false,
                'overridden' => null !== $projectEntity && $this->addonManager->hasProjectOverride($manifest->id, $projectEntity),
                'can_toggle' => null === $projectEntity || AddonScope::Workspace !== $manifest->scope,
            ];
        }

        return $this->render('marketplace/index.html.twig', [
            'project' => $projectEntity,
            'projects' => $this->projectScope->projects(),
            'is_scoped' => null !== $this->projectScope->slugs(),
            'category' => $categoryEnum,
            'categories' => AddonCategory::cases(),
            'category_counts' => $categoryCounts,
            'all_count' => \count($allAddons),
            'active_count' => $activeCount,
            'active_preset' => $this->detectActivePreset($enabledById, $allAddons),
            'cards' => $cards,
        ]);
    }

    #[Route('/marketplace/{id}/toggle', name: 'marketplace_toggle', methods: ['POST'])]
    public function toggle(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('marketplace-toggle-'.$id, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $projectEntity = $this->resolveTargetProject($request);
        $addon = $this->addonManager->get($id);
        if (null === $addon) {
            throw $this->createNotFoundException(\sprintf('Addon "%s" not found.', $id));
        }

        $payload = $request->getPayload();
        $nextEnabled = $payload->has('enabled')
            ? $payload->getBoolean('enabled')
            : !$this->addonManager->isEnabled($id, $projectEntity);

        try {
            $this->addonManager->setEnabled($id, $nextEnabled, $projectEntity);
            $this->addFlash('success', new TranslatableMessage(
                $nextEnabled ? 'marketplace.flash.enabled' : 'marketplace.flash.disabled',
                ['name' => $this->translator->trans($addon->manifest()->name)],
            ));
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->back($request);
    }

    #[Route('/marketplace/preset/{preset}', name: 'marketplace_preset', methods: ['POST'])]
    public function preset(string $preset, Request $request): Response
    {
        if (!\in_array($preset, AddonManager::PRESETS, true)) {
            throw $this->createNotFoundException(\sprintf('Preset "%s" not found.', $preset));
        }

        if (!$this->isCsrfTokenValid('marketplace-preset-'.$preset, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $projectEntity = $this->resolveTargetProject($request);
        $this->addonManager->applyPreset($preset, $projectEntity);

        $this->addFlash('success', new TranslatableMessage(
            'marketplace.flash.preset_applied',
            ['preset' => $this->translator->trans('marketplace.preset.'.$preset.'.name')],
        ));

        return $this->back($request);
    }

    private function resolveTargetProject(Request $request): ?Project
    {
        $slug = $request->query->get('project');
        if (!\is_string($slug) || '' === $slug) {
            $payloadSlug = $request->getPayload()->get('project');
            $slug = \is_string($payloadSlug) && '' !== $payloadSlug ? $payloadSlug : null;
        }

        $project = $this->projectScope->bySlug($slug);
        if (null === $project && null !== $this->projectScope->slugs()) {
            throw new AccessDeniedHttpException('Workspace-wide addon configuration requires full workspace access.');
        }

        return $project;
    }

    /**
     * @param array<string, bool>             $enabledById
     * @param list<\App\Addon\AddonInterface> $allAddons
     */
    private function detectActivePreset(array $enabledById, array $allAddons): ?string
    {
        if ([] === $allAddons) {
            return null;
        }

        $allEnabled = true;
        foreach ($enabledById as $enabled) {
            if (!$enabled) {
                $allEnabled = false;
                break;
            }
        }
        if ($allEnabled) {
            return AddonManager::PRESET_FACTORY;
        }

        $factoryOff = !($enabledById[FactoryAddon::ID] ?? false)
            && !($enabledById[StudioAddon::ID] ?? false)
            && !($enabledById[CodeGraphAddon::ID] ?? false);

        if (!$factoryOff) {
            return null;
        }

        foreach ($allAddons as $addon) {
            $manifest = $addon->manifest();
            if (\in_array(AddonManager::PRESET_BUSINESS, $manifest->presets, true) && !($enabledById[$manifest->id] ?? false)) {
                return null;
            }
        }

        return AddonManager::PRESET_BUSINESS;
    }

    private function back(Request $request): Response
    {
        $filters = array_filter([
            'project' => $request->query->get('project'),
            'category' => $request->query->get('category'),
        ], static fn (mixed $value): bool => \is_string($value) && '' !== $value);

        return $this->redirectToRoute('marketplace_index', $filters);
    }
}
