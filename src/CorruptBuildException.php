<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * The shipped SPA bundle is not usable — `dist/index.html` is present but has
 * lost its `#sg-config` injection point.
 *
 * A distinct type rather than a bare `RuntimeException` so `run()` can catch
 * exactly this and nothing else. It is the one failure where the package sets
 * a `500` before letting the exception reach the consumer's error handler; every
 * other throw — from asset serving, rendering, an API endpoint, route parsing —
 * propagates with the response code untouched, which is what the front
 * controller did before {@see Styleguide::handle()} existed.
 *
 * A review caught the alternative: catching `\Throwable` around the whole
 * dispatch reproduced the 500 for the corrupt build AND invented it for every
 * other exception, erasing a status a consumer had deliberately set.
 *
 * Extends `RuntimeException` so existing `catch (\RuntimeException)` in
 * consumer code keeps working.
 */
final class CorruptBuildException extends \RuntimeException
{
    /**
     * The shape a usable `dist/index.html` must contain.
     *
     * Lives here, on the exception that names the condition, so the runtime
     * that throws and `styleguide doctor` — which exists to report the same
     * condition before a visitor meets it — cannot disagree about what
     * "usable" means. A review found them disagreeing: doctor looked for the
     * id alone, so a build missing the `type` attribute passed the diagnostic
     * and then threw on the first request.
     */
    public const INJECTION_POINT_PATTERN = '/<script id="sg-config" type="application\/json">.*?<\/script>/s';
}
