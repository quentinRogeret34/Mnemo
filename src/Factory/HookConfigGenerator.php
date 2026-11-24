<?php

namespace App\Factory;

use App\Entity\Project;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Generates ready-to-paste local hook snippets (Claude Code settings.json + curl) so external dev agents
 * automatically report started/finished sessions to Mnemo.
 */
final class HookConfigGenerator
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function claudeCodeHooksJson(?Project $project): string
    {
        $slug = $project?->getSlug() ?? 'mon-projet';
        $hookUrl = $this->urlGenerator->generate('api_factory_session_hook', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $startCmd = \sprintf(
            'curl -sS -X POST %s -H "Authorization: Bearer $MNEMO_API_KEY" -H "Content-Type: application/json" -d \'{"action":"start","project":"%s","title":"Claude Code session","client":"claude-code","runner":"claude_cli"}\' > /tmp/mnemo-session.json || true',
            $hookUrl,
            $slug,
        );
        $stopCmd = \sprintf(
            'SID=$(jq -r .session_id /tmp/mnemo-session.json 2>/dev/null); [ -n "$SID" ] && curl -sS -X POST %s -H "Authorization: Bearer $MNEMO_API_KEY" -H "Content-Type: application/json" -d "{\\"action\\":\\"end\\",\\"sessionId\\":\\"$SID\\",\\"status\\":\\"succeeded\\"}" || true',
            $hookUrl,
        );

        $config = [
            'hooks' => [
                'SessionStart' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => $startCmd,
                    ]],
                ]],
                'Stop' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => $stopCmd,
                    ]],
                ]],
            ],
        ];

        return (string) json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    public function hookEndpointUrl(): string
    {
        return $this->urlGenerator->generate('api_factory_session_hook', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
