<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Bridge\Symfony\EventListener;

use Parisek\Styleguide\Bridge\Symfony\DependencyInjection\StyleguideExtension;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Keeps the web debug toolbar out of component previews, and keeps the
 * profile.
 *
 * A preview is a complete HTML document served into an iframe, which is
 * exactly what the toolbar injects itself into. Left alone, it renders across
 * the bottom of every component in the catalogue and into every screenshot a
 * visual test takes.
 *
 * `Profiler::disable()` would stop the toolbar too, and it would throw away the
 * profile — and "which component render is slow" is the question a profiler
 * answers here. So only the injection stops: `ProfilerListener` sets
 * `X-Debug-Token` at priority -100, `WebDebugToolbarListener` reads it at
 * -128, and this listener runs at -110, in between, and removes it. The
 * profile stays browsable at `/_profiler`.
 *
 * Symfony 7 dropped `framework.profiler.matcher`, the configuration node that
 * used to say this, and no replacement accepts an exclusion.
 *
 * @internal registered by StyleguideKernel; not a consumer extension point
 */
final class ToolbarOutOfPreviewsListener
{
    public const PRIORITY = -110;

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // The render route only. The SPA shell at /styleguide/ is a page on
        // which the toolbar is useful.
        if (!str_starts_with($event->getRequest()->getPathInfo(), StyleguideExtension::SUPPORTED_PREFIX . '/render/')) {
            return;
        }

        // X-Debug-Token-Link goes too. It is only ever set beside the token,
        // and a link to a toolbar that never renders reads as a bug in the
        // network panel.
        $event->getResponse()->headers->remove('X-Debug-Token');
        $event->getResponse()->headers->remove('X-Debug-Token-Link');
    }
}
