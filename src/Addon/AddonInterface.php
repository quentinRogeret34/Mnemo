<?php

namespace App\Addon;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.addon')]
interface AddonInterface
{
    public function manifest(): AddonManifest;
}
