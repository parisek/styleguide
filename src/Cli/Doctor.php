<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Cli;

use Parisek\Styleguide\Styleguide;
use Parisek\Styleguide\Twig\StyleguideTwigExtension;
use Twig\Error\LoaderError;

/**
 * Diagnoses one project's styleguide configuration.
 *
 * Deliberately narrow: it reports what `fromYaml()` accepts and the runtime
 * then fails on, or silently ignores. Everything `fromYaml()` already refuses
 * — a missing required key, a key of the wrong type, a run-truth key in the
 * YAML — is reported here as the one finding it is, with the library's own
 * message, rather than re-validated a second time. Two validators of the same
 * YAML would drift, and the one a consumer actually meets is `fromYaml()`.
 *
 * What is left is the set of failures nothing catches today:
 *
 * - A path that resolves but does not exist. `registerConventionalNamespaces()`
 *   skips a namespace whose directory is absent, so a typo in
 *   `namespaces.foo` never errors — templates under `@foo` just stop
 *   resolving, and the message a developer sees is "template not found".
 * - A built `dist/` that has gone stale. A missing `#sg-config` injection
 *   point throws `CorruptBuildException`, but only on a live request; an
 *   asset the shell references and the build no longer contains fails in the
 *   browser, where PHP never learns about it.
 * - `base_url`. `fromYaml()` accepts it and nothing implements it.
 * - The helper names the package puts on a Twig environment, which a consumer
 *   with its own `component_*` needs before Twig locks its extension set.
 *
 * Every check answers a question a developer would otherwise answer by
 * deploying.
 */
final class Doctor
{
    /**
     * @return list<DoctorFinding>
     */
    public function run(string $configPath): array
    {
        try {
            $styleguide = Styleguide::fromYaml($configPath);
        } catch (\Throwable $e) {
            // Nothing further is checkable: every other check needs the
            // resolved configuration this call would have produced. Reported
            // with the library's own message so the text a consumer reads
            // here is the text they would have met at runtime.
            return [new DoctorFinding(
                LintSeverity::Error,
                'config',
                $e->getMessage(),
                // A loader error at this point is always templates_path: it is
                // the only directory handed to Twig before anything renders,
                // and Twig's own message names the path without naming the key
                // the reader has to edit.
                $e instanceof LoaderError
                    ? 'That is bootstrap.templates_path. Relative paths resolve against the directory '
                        . 'holding styleguide.yaml, not the working directory.'
                    : 'Fix styleguide.yaml and run doctor again — the remaining checks need a loadable '
                        . 'config.',
            )];
        }

        return $this->runFor($styleguide, $configPath);
    }

    /**
     * The checks, against a `Styleguide` that is already built.
     *
     * Separate from {@see run()} so a test can point the dist checks at a
     * throwaway directory. `dist_path` is a run-truth key — `fromYaml()`
     * refuses it — so there is no way to reach those two branches through a
     * YAML file, and an untestable check is one that quietly stops working.
     *
     * @return list<DoctorFinding>
     */
    public function runFor(Styleguide $styleguide, string $configPath): array
    {
        $diagnostics = $styleguide->diagnostics();

        return [
            ...$this->checkPaths($diagnostics['paths']),
            ...$this->checkDist($diagnostics['dist']),
            ...$this->checkBaseUrl($configPath),
            ...$this->checkRender($styleguide),
            ...$this->reportHelpers(),
        ];
    }

    /**
     * @param array<string, string> $paths
     * @return list<DoctorFinding>
     */
    private function checkPaths(array $paths): array
    {
        $findings = [];
        foreach ($paths as $key => $path) {
            // typography_config names a file; every other key names a
            // directory. Checking the wrong one would pass a directory named
            // like the config file, which is the sort of near-miss a
            // diagnostic exists to catch.
            $exists = $key === 'typography_config' ? is_file($path) : is_dir($path);
            if ($exists) {
                continue;
            }

            $findings[] = new DoctorFinding(
                LintSeverity::Error,
                'paths',
                sprintf("bootstrap.%s points at '%s', which does not exist.", $key, $path),
                str_starts_with($key, 'namespaces.')
                    ? 'A namespace whose directory is absent is skipped silently, so templates using it '
                        . 'fail with "template not found" instead. Fix the path or drop the namespace.'
                    : 'Relative paths resolve against the directory holding styleguide.yaml, not the '
                        . 'working directory.',
            );
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function checkDist(string $distRoot): array
    {
        $index = $distRoot . '/index.html';
        if (!is_file($index)) {
            return [new DoctorFinding(
                LintSeverity::Error,
                'dist',
                sprintf("The built SPA is missing: no index.html in '%s'.", $distRoot),
                'Run the frontend build, or install the package from a release rather than a git '
                    . 'archive that excludes dist/.',
            )];
        }

        $html = (string) file_get_contents($index);
        $findings = [];

        if (!str_contains($html, 'id="sg-config"')) {
            // The CorruptBuildException condition. Worth its own check
            // because the exception only ever fires on a live request, which
            // means a broken deploy is discovered by a visitor.
            $findings[] = new DoctorFinding(
                LintSeverity::Error,
                'dist',
                'dist/index.html has lost its #sg-config injection point.',
                'Rebuild the frontend. Without it the SPA cannot be handed its configuration and '
                    . 'every catalogue request answers 500.',
            );
        }

        foreach ($this->referencedAssets($html) as $asset) {
            if (is_file($distRoot . '/' . $asset)) {
                continue;
            }
            // PHP never learns about this one: the shell is served, the
            // browser asks for the asset, and the page is blank.
            $findings[] = new DoctorFinding(
                LintSeverity::Error,
                'dist',
                sprintf(
                    "dist/index.html references '/styleguide/assets/%s', and the build does not contain '%s'.",
                    $asset,
                    $asset,
                ),
                'The built shell and its assets are out of step. Rebuild the frontend and commit dist/ '
                    . 'as one change.',
            );
        }

        return $findings;
    }

    /**
     * Asset paths the built shell requests, relative to the dist root.
     *
     * Only the package's own `/styleguide/assets/…` references. An absolute
     * URL or a path outside the prefix belongs to the host application, and
     * doctor knows nothing about where that is served from.
     *
     * The `assets/` segment is stripped: it is part of the route, not of the
     * layout on disk. `/styleguide/assets/styleguide.js` is served from
     * `dist/styleguide.js`, because the router hands AssetServer the path
     * BELOW that segment and AssetServer resolves it against the dist root.
     * Keeping the segment made every asset look missing — the first thing
     * running this command against the real dist/ reported.
     *
     * @return list<string>
     */
    private function referencedAssets(string $html): array
    {
        preg_match_all('~(?:src|href)="/styleguide/assets/([^"]+)"~', $html, $matches);

        /** @var list<string> $assets */
        $assets = array_values(array_unique($matches[1]));

        return $assets;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function checkBaseUrl(string $configPath): array
    {
        try {
            $data = (array) \Symfony\Component\Yaml\Yaml::parseFile($configPath);
        } catch (\Throwable) {
            // Unreachable in practice: fromYaml() parsed this file already.
            return [];
        }

        $bootstrap = is_array($data['bootstrap'] ?? null) ? $data['bootstrap'] : [];
        if (!isset($bootstrap['base_url'])) {
            return [];
        }

        return [new DoctorFinding(
            LintSeverity::Warning,
            'base_url',
            sprintf("bootstrap.base_url is set to '%s' and nothing implements it.", (string) $bootstrap['base_url']),
            'The mount point is /styleguide, hardcoded through the PHP router, the Vue history base '
                . 'and the asset URLs in the built shell. Remove the key so it does not read as a '
                . 'setting that took effect.',
        )];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function checkRender(Styleguide $styleguide): array
    {
        $inventory = $styleguide->inventory();
        if ($inventory === []) {
            return [new DoctorFinding(
                LintSeverity::Warning,
                'render',
                'The catalogue is empty: templates_path holds no component, page or doc fixture.',
                'A fixture is a `<kind>/<slug>/styleguide.twig` next to the template. Without one the '
                    . 'catalogue loads and shows nothing.',
            )];
        }

        $first = $inventory[0];
        try {
            $styleguide->renderObserved($first['kind'], $first['slug'], $first['variant']);
        } catch (\Throwable $e) {
            // One render, not all of them, and NOT a check on the fixture's
            // Twig. `renderObserved()` tolerates an unknown helper by design
            // — that is what `Styleguide::invokeTwigFunction()`'s fallback is
            // for — so a broken template comes back as rendered output, not
            // as a throw. `styleguide lint` is what walks fixtures.
            //
            // What reaches this branch is configuration that only fails once
            // something renders: a translations catalogue or typography
            // config that loads and then cannot be used. Rare, which is why
            // there is no test standing over it; left in because the
            // alternative is doctor itself dying with a stack trace.
            return [new DoctorFinding(
                LintSeverity::Error,
                'render',
                sprintf(
                    "Rendering %s '%s'%s failed: %s",
                    $first['kind'],
                    $first['slug'],
                    $first['variant'] === null ? '' : sprintf(" variant '%s'", $first['variant']),
                    $e->getMessage(),
                ),
                'The configuration loads but cannot render. Run `styleguide lint` for the whole tree.',
            )];
        }

        return [];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function reportHelpers(): array
    {
        $extension = new StyleguideTwigExtension([]);

        $names = [];
        foreach ($extension->getFunctions() as $function) {
            $names[] = $function->getName() . '()';
        }
        foreach ($extension->getFilters() as $filter) {
            $names[] = '|' . $filter->getName();
        }
        sort($names);

        return [new DoctorFinding(
            LintSeverity::Notice,
            'twig',
            sprintf('%d helpers are registered on the Twig environment: %s', count($names), implode(', ', $names)),
            'A consumer defining a helper of the same name must register it AFTER the styleguide, and '
                . 'must not read from the environment first — Twig locks its extension set on the first '
                . 'read. See README § *If your environment is already initialised*.',
        )];
    }
}
