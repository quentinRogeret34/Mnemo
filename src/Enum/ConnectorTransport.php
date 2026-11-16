<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ConnectorTransport: string implements TranslatableInterface
{
    case Http = 'http';
    case Stdio = 'stdio';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.connector_transport.'.$this->value, locale: $locale);
    }
}
