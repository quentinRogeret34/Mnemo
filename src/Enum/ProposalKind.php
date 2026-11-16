<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ProposalKind: string implements TranslatableInterface
{
    case Learning = 'learning';
    case Duplicate = 'duplicate';
    case Contradiction = 'contradiction';
    case Refuted = 'refuted';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.proposal_kind.'.$this->value, locale: $locale);
    }
}
