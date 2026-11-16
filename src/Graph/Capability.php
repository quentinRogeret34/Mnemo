<?php

namespace App\Graph;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a project does, detected from concrete facts in its file paths, after AIDD's capability signals
 * (github.com/ai-driven-dev/framework, MIT). The code graph holds parsed code files only: manifests and most
 * configuration files are not there, so a capability visible only in them is missed.
 */
enum Capability: string implements TranslatableInterface
{
    case Api = 'api';
    case Ui = 'ui';
    case Database = 'database';
    case Auth = 'auth';
    case Realtime = 'realtime';
    case Messaging = 'messaging';
    case Cli = 'cli';
    case Deployment = 'deployment';
    case Infra = 'infra';
    case Mobile = 'mobile';
    case Desktop = 'desktop';
    case Data = 'data';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'API HTTP',
            self::Ui => 'Interface',
            self::Database => 'Base de données',
            self::Auth => 'Authentification',
            self::Realtime => 'Temps réel',
            self::Messaging => 'Messages asynchrones',
            self::Cli => 'Ligne de commande',
            self::Deployment => 'Déploiement',
            self::Infra => 'Infrastructure',
            self::Mobile => 'Mobile',
            self::Desktop => 'Desktop',
            self::Data => 'Données',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.capability.'.$this->value, locale: $locale);
    }

    /**
     * Case-insensitive pattern matched against each file path relative to the repository root.
     */
    public function pattern(): string
    {
        return match ($this) {
            self::Api => '~(^|/)(controllers?|routes?|api|endpoints?)/|controller\.\w+$~i',
            self::Ui => '~(^|/)(components|pages|views|templates|layouts)/|\.(vue|svelte|jsx|tsx|twig|blade\.php)$~i',
            self::Database => '~(^|/)(migrations?|entity|entities|repositor(y|ies)|models?)/|schema\.(prisma|sql|rb)$|repository\.\w+$~i',
            self::Auth => '~(^|/)(auth|security|authentication)/|(authenticator|voter|auth)\w*\.\w+$~i',
            self::Realtime => '~(websocket|socket\.io|mercure|realtime|(^|/)sse/)~i',
            self::Messaging => '~(^|/)(messages?|messenger|queues?|consumers?|producers?|jobs|workers?)/|(message_?handler|consumer|producer)\.\w+$~i',
            self::Cli => '~(^|/)(commands?|cli|console|bin)/|command\.\w+$~i',
            self::Deployment => '~(^|/)dockerfile$|(^|/)(docker-)?compose\.ya?ml$|(^|/)\.github/workflows/|(^|/)\.gitlab-ci\.yml$~i',
            self::Infra => '~\.tf$|(^|/)(k8s|kubernetes|helm|charts|terraform|pulumi)/~i',
            self::Mobile => '~(^|/)(ios|android)/|pubspec\.yaml$|(^|/)podfile$|\.(swift|kt|dart)$~i',
            self::Desktop => '~(^|/)(electron|src-tauri)/|electron\.\w+$~i',
            self::Data => '~\.ipynb$|(^|/)(notebooks|pipelines|etl|dags)/~i',
        };
    }
}
