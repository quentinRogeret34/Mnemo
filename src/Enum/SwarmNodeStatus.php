<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum SwarmNodeStatus: string implements TranslatableInterface
{
    case Idle = 'idle';
    case Busy = 'busy';
    case Offline = 'offline';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.swarm_node_status.'.$this->value, locale: $locale);
    }
}
