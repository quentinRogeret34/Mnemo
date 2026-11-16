<?php

namespace App\Locale;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * The language picked on the appearance page wins over the Accept-Language header:
 * it goes in the _locale attribute, which the framework's LocaleListener (priority 16) reads first.
 */
#[AsEventListener(priority: 20)]
final class SessionLocaleListener
{
    public const SESSION_KEY = '_locale';

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->has('_locale') || !$request->hasPreviousSession()) {
            return;
        }

        $locale = $request->getSession()->get(self::SESSION_KEY);
        if (\is_string($locale)) {
            $request->attributes->set('_locale', $locale);
        }
    }
}
