<?php

namespace App\Factory\Swarm;

use App\Entity\SwarmOrder;
use App\Entity\SwarmRun;
use App\Repository\SwarmOrderRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Renders signed Markdown briefs and copy-paste join prompts (MCP and URL/curl style) for external Hub clients.
 */
final class SwarmBriefGenerator
{
    public function __construct(
        private readonly ProjectContextBuilder $contextBuilder,
        private readonly SwarmOrderRepository $orderRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function swarmBriefMarkdown(SwarmRun $swarm, #[\SensitiveParameter] string $rawToken): string
    {
        $project = $swarm->getProject();
        $context = $this->contextBuilder->build($project, $swarm->getSourceRuleCode());
        $pendingOrders = $this->orderRepository->pendingForSwarm($swarm);

        $joinUrl = $this->urlGenerator->generate(
            'hub_swarm_join',
            ['publicId' => $swarm->getPublicId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $waitUrl = $this->urlGenerator->generate(
            'hub_swarm_wait_order',
            ['publicId' => $swarm->getPublicId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $reportTemplateUrl = str_replace(
            'ORDER_ID',
            '$ORDER_ID',
            $this->urlGenerator->generate(
                'hub_swarm_report_order',
                ['publicId' => $swarm->getPublicId(), 'orderPublicId' => 'ORDER_ID'],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        );
        $completeTemplateUrl = str_replace(
            'ORDER_ID',
            '$ORDER_ID',
            $this->urlGenerator->generate(
                'hub_swarm_complete_order',
                ['publicId' => $swarm->getPublicId(), 'orderPublicId' => 'ORDER_ID'],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        );
        $disconnectTemplateUrl = str_replace(
            'NODE_ID',
            '$NODE_ID',
            $this->urlGenerator->generate(
                'hub_swarm_disconnect',
                ['publicId' => $swarm->getPublicId(), 'nodePublicId' => 'NODE_ID'],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        );

        $lines = [
            \sprintf('# Mnemo Swarm Hub — %s', $swarm->getTitle()),
            '',
            \sprintf('- **Swarm ID** : `%s`', $swarm->getPublicId()),
            \sprintf('- **Projet** : %s (`%s`)', $project->getName(), $project->getSlug()),
            \sprintf('- **Statut** : `%s`', $swarm->getStatus()->value),
        ];
        if (null !== $swarm->getBaseBranch()) {
            $lines[] = \sprintf('- **Branche de base** : `%s`', $swarm->getBaseBranch());
        }
        if (null !== $swarm->getSourceRuleCode()) {
            $lines[] = \sprintf('- **Règle métier cible** : `%s`', $swarm->getSourceRuleCode());
        }

        $lines[] = '';
        $lines[] = '## Objectif de la ruche';
        $lines[] = '';
        $lines[] = $swarm->getGoal();
        $lines[] = '';
        $lines[] = '## Contexte Mnemo du projet';
        $lines[] = '';
        $lines[] = $context;
        $lines[] = '';
        $lines[] = '## Ordres en attente';
        $lines[] = '';

        if ([] === $pendingOrders) {
            $lines[] = '_Aucun ordre en attente pour le moment. Rejoins la ruche puis attends les prochains ordres via le endpoint `wait`._';
        } else {
            foreach ($pendingOrders as $order) {
                $target = null !== $order->getTargetNode() ? ' · cible `@'.$order->getTargetNode()->getName().'`' : '';
                $files = [] !== $order->getFilesHint() ? ' · fichiers : `'.implode('`, `', $order->getFilesHint()).'`' : '';
                $lines[] = \sprintf(
                    '- **`%s`** [%s] **%s**%s%s — %s',
                    $order->getPublicId(),
                    $order->getKind()->value,
                    $order->getTitle(),
                    $target,
                    $files,
                    mb_strimwidth($order->getInstructions(), 0, 220, '…'),
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Protocole client externe (HTTP + `curl`)';
        $lines[] = '';
        $lines[] = 'Conserve le jeton dans une variable d’environnement locale puis exécute la boucle suivante :';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = \sprintf('export MNEMO_HUB_TOKEN="%s"', $rawToken);
        $lines[] = '';
        $lines[] = '# 1. Rejoindre la ruche et récupérer NODE_ID';
        $lines[] = \sprintf(
            'curl -sS -X POST "%s" -H "Authorization: Bearer $MNEMO_HUB_TOKEN" -H "Content-Type: application/json" -d \'{"name":"my-agent","client":"cli","role":"developer"}\'',
            $joinUrl,
        );
        $lines[] = '';
        $lines[] = '# 2. Attendre le prochain ordre (long-polling jusqu\'à 20 s) — boucler tant que status != closed';
        $lines[] = \sprintf(
            'curl -sS "%s?node=$NODE_ID&timeout=20" -H "Authorization: Bearer $MNEMO_HUB_TOKEN"',
            $waitUrl,
        );
        $lines[] = '';
        $lines[] = '# 3. Signaler une étape intermédiaire pendant l\'exécution';
        $lines[] = \sprintf(
            'curl -sS -X POST "%s" -H "Authorization: Bearer $MNEMO_HUB_TOKEN" -H "Content-Type: application/json" -d \'{"nodeId":"\'$NODE_ID\'","message":"Analyse en cours","files":[]}\'',
            $reportTemplateUrl,
        );
        $lines[] = '';
        $lines[] = '# 4. Clôturer l\'ordre avec le résumé, la sortie Markdown et les fichiers touchés';
        $lines[] = \sprintf(
            'curl -sS -X POST "%s" -H "Authorization: Bearer $MNEMO_HUB_TOKEN" -H "Content-Type: application/json" -d \'{"nodeId":"\'$NODE_ID\'","status":"completed","summary":"Résumé concis","output":"Détails Markdown","files":[],"branch":null}\'',
            $completeTemplateUrl,
        );
        $lines[] = '';
        $lines[] = '# 5. Se déconnecter proprement en fin de session';
        $lines[] = \sprintf(
            'curl -sS -X POST "%s" -H "Authorization: Bearer $MNEMO_HUB_TOKEN"',
            $disconnectTemplateUrl,
        );
        $lines[] = '```';
        $lines[] = '';

        return implode("\n", $lines);
    }

    public function orderBriefMarkdown(
        SwarmRun $swarm,
        SwarmOrder $order,
        #[\SensitiveParameter] string $rawToken,
    ): string {
        $project = $swarm->getProject();
        $context = $this->contextBuilder->build($project, $order->getRuleCode() ?? $swarm->getSourceRuleCode());

        $reportUrl = $this->urlGenerator->generate(
            'hub_swarm_report_order',
            ['publicId' => $swarm->getPublicId(), 'orderPublicId' => $order->getPublicId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $completeUrl = $this->urlGenerator->generate(
            'hub_swarm_complete_order',
            ['publicId' => $swarm->getPublicId(), 'orderPublicId' => $order->getPublicId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $lines = [
            \sprintf('# Ordre Mnemo — %s', $order->getTitle()),
            '',
            \sprintf('- **Order ID** : `%s`', $order->getPublicId()),
            \sprintf('- **Type** : `%s`', $order->getKind()->value),
            \sprintf('- **Statut** : `%s`', $order->getStatus()->value),
            \sprintf('- **Ruche** : %s (`%s`)', $swarm->getTitle(), $swarm->getPublicId()),
            \sprintf('- **Projet** : %s (`%s`)', $project->getName(), $project->getSlug()),
        ];

        if (null !== $order->getRuleCode()) {
            $lines[] = \sprintf('- **Règle métier associée** : `%s`', $order->getRuleCode());
        }
        if ([] !== $order->getFilesHint()) {
            $lines[] = \sprintf('- **Fichiers suggérés** : `%s`', implode('`, `', $order->getFilesHint()));
        }

        $lines[] = '';
        $lines[] = '## Instructions';
        $lines[] = '';
        $lines[] = $order->getInstructions();
        $lines[] = '';
        $lines[] = '## Contexte Mnemo du projet';
        $lines[] = '';
        $lines[] = $context;
        $lines[] = '';
        $lines[] = '## Retour de résultat (HTTP + `curl`)';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = \sprintf('export MNEMO_HUB_TOKEN="%s"', $rawToken);
        $lines[] = '';
        $lines[] = '# Optionnel : signaler l\'avancement';
        $lines[] = \sprintf(
            'curl -sS -X POST "%s" -H "Authorization: Bearer $MNEMO_HUB_TOKEN" -H "Content-Type: application/json" -d \'{"message":"Avancement","files":[]}\'',
            $reportUrl,
        );
        $lines[] = '';
        $lines[] = '# Obligatoire : soumettre le résultat final';
        $lines[] = \sprintf(
            'curl -sS -X POST "%s" -H "Authorization: Bearer $MNEMO_HUB_TOKEN" -H "Content-Type: application/json" -d \'{"status":"completed","summary":"Résumé concis","output":"Détails Markdown","files":[],"branch":null}\'',
            $completeUrl,
        );
        $lines[] = '```';
        $lines[] = '';

        return implode("\n", $lines);
    }

    public function mcpJoinPrompt(SwarmRun $swarm): string
    {
        return implode("\n", [
            \sprintf('Rejoins la ruche Mnemo "%s" (swarmId="%s") via l’outil MCP `join_swarm` (choisis un `name` lisible pour ce poste, ton `client` et ta `branch` courante).', $swarm->getTitle(), $swarm->getPublicId()),
            'Ensuite, boucle sur `wait_swarm_order(swarmId, nodeId, timeout: 20)` :',
            '- Tant que `status === "idle"`, rappelle `wait_swarm_order`.',
            '- Dès qu’un ordre arrive (`status === "order"`), exécute-le localement en respectant le contexte projet, envoie des jalons via `report_swarm_order`, puis clôture avec `complete_swarm_order(summary, output, files, branch)` avant de reprendre l’attente.',
            '- Arrête la boucle uniquement quand `status === "closed"` ou sur demande explicite de l’utilisateur.',
        ]);
    }

    public function urlJoinPrompt(SwarmRun $swarm, #[\SensitiveParameter] string $rawToken): string
    {
        $briefUrl = $this->urlGenerator->generate(
            'hub_swarm_brief',
            ['publicId' => $swarm->getPublicId(), 'token' => $rawToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return \sprintf(
            'Lis les instructions sur %s et suis le protocole pour rejoindre la ruche Mnemo "%s" (`%s`), attendre les ordres en direct et renvoyer tes résultats.',
            $briefUrl,
            $swarm->getTitle(),
            $swarm->getPublicId(),
        );
    }

    public function orderDirectUrl(
        SwarmRun $swarm,
        SwarmOrder $order,
        #[\SensitiveParameter] string $rawToken,
    ): string {
        return $this->urlGenerator->generate(
            'hub_swarm_order_brief',
            [
                'publicId' => $swarm->getPublicId(),
                'orderPublicId' => $order->getPublicId(),
                'token' => $rawToken,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
