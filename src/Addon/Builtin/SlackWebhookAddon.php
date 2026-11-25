<?php

namespace App\Addon\Builtin;

use App\Addon\AddonCategory;
use App\Addon\AddonInterface;
use App\Addon\AddonManifest;
use App\Addon\AddonScope;

final readonly class SlackWebhookAddon implements AddonInterface
{
    public const ID = 'webhooks';

    public function manifest(): AddonManifest
    {
        return new AddonManifest(
            id: self::ID,
            name: 'addon.webhooks.name',
            description: 'addon.webhooks.description',
            category: AddonCategory::Integration,
            scope: AddonScope::Project,
            icon: 'lucide:plug',
            color: 'warning',
            defaultEnabled: true,
            presets: ['business', 'factory'],
        );
    }
}
