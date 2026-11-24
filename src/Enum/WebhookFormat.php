<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum WebhookFormat: string implements TranslatableInterface
{
    case Slack = 'slack';
    case Discord = 'discord';
    case Teams = 'teams';
    case Generic = 'generic';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.webhook_format.'.$this->value, locale: $locale);
    }
}
