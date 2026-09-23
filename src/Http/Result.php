<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Http;

/**
 * @internal Not SemVer-covered while the request/result seam is being built
 *           out. It becomes public with the Symfony bundle, which is the
 *           consumer it exists for.
 *
 * What a styleguide route produced: a status, headers, and a body that is
 * either text or a file on disk.
 *
 * The package writes its responses with `http_response_code()`, `header()` and
 * `echo`, from eleven places, and ends the request with `exit`. That is fine
 * for a front controller and impossible for a host application: a Symfony
 * controller has to RETURN a response, and the package's own tests have to
 * drive a subprocess to survive the `exit` (see `tests/SpaConfigTest`).
 *
 * This is the value that lets both happen. `Styleguide::run()` emits one and
 * keeps behaving exactly as it did; a controller maps one to a `Response`.
 *
 * A file body stays a path rather than being read into memory. `AssetServer`
 * serves the SPA bundle, and a caller that wants to stream it — `readfile()`,
 * `BinaryFileResponse`, `X-Sendfile` — cannot un-read a string.
 */
final class Result
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly ?string $file,
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $headers, $body, null);
    }

    /**
     * A body to be read from disk by whoever emits this.
     *
     * @param array<string, string> $headers
     */
    public static function file(string $path, int $status = 200, array $headers = []): self
    {
        return new self($status, $headers, null, $path);
    }

    /**
     * A JSON body with the two headers every `/api/*` endpoint sends.
     *
     * Named rather than repeated at five call sites, because the pair is part
     * of the contract: `no-cache` is not decoration — the catalogue changes
     * whenever a template does, and a cached `/api/components` is a stale
     * sidebar.
     */
    public static function json(string $body, int $status = 200): self
    {
        return new self($status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ], $body, null);
    }

    /**
     * No body at all — a `304`, or a `404` the package does not decorate.
     *
     * Distinct from `text('')`: an empty string is a body, and a caller
     * mapping this to a framework response should be able to tell "nothing to
     * send" from "send nothing".
     *
     * @param array<string, string> $headers
     */
    public static function empty(int $status, array $headers = []): self
    {
        return new self($status, $headers, null, null);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, [$name => $value] + $this->headers, $this->body, $this->file);
    }

    /**
     * Write this result the way `run()` always has.
     *
     * Kept beside the value rather than in `Styleguide` so the emitting rules
     * — status first, then headers, then body — live in one place, and so a
     * test can assert a result without a subprocess.
     */
    public function emit(): void
    {
        // 200 is PHP's own default, and the pre-seam code never set it: a
        // successful asset emitted headers and the file without touching the
        // response code. Calling it unconditionally would therefore CHANGE
        // behaviour for a front controller that had already chosen a status
        // before dispatching — main left that alone, and so does this.
        //
        // Only the legacy emitter cares. A framework mapping reads `$status`
        // directly, where 200 has to be explicit, which is why the value still
        // carries it.
        if ($this->status !== 200) {
            http_response_code($this->status);
        }

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->file !== null) {
            readfile($this->file);

            return;
        }

        if ($this->body !== null) {
            echo $this->body;
        }
    }
}
