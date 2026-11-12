<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler;

use Buggregator\Trap\Client\Profiler\Internal\Driver;
use Buggregator\Trap\Client\Profiler\Internal\ServerSender;
use Buggregator\Trap\Client\Profiler\Internal\Settings;
use Buggregator\Trap\Client\Profiler\Internal\TrapSender;
use Buggregator\Trap\Client\TrapHandle;

/**
 * Profiling configuration made by {@see TrapHandle::profile()}; nothing runs until {@see run()} or {@see start()}.
 *
 * Without any destination the profile goes to Trap; with any, Trap gets it only through {@see toTrap()}.
 * The configuration may be run several times, each run makes a new profile.
 *
 * @api
 */
final class Profiling
{
    private readonly Settings $settings;

    private function __construct(?Driver $profiler)
    {
        $this->settings = new Settings($profiler, $profiler ?? $this->detect());
    }

    /**
     * @internal Use {@see TrapHandle::profile()}.
     */
    public static function create(?Driver $profiler = null): self
    {
        return new self($profiler);
    }

    /**
     * @param non-empty-string $name
     */
    public function name(string $name): self
    {
        $this->settings->name = $name;
        return $this;
    }

    /**
     * Adds tags, overwriting the ones with the same keys.
     *
     * @param array<non-empty-string, string> $tags
     */
    public function tags(array $tags): self
    {
        $this->settings->tags = \array_merge($this->settings->tags, $tags);
        return $this;
    }

    /**
     * Profile only if the condition is true. A callable is evaluated on every {@see run()} or {@see start()}.
     *
     * @param bool|callable(): bool $condition
     */
    public function if(bool|callable $condition): self
    {
        $this->settings->if = \is_bool($condition) ? $condition : $condition(...);
        return $this;
    }

    /**
     * Profile only a share of the runs, drawn on every {@see run()} or {@see start()}.
     *
     * @param float $rate Probability from 0 to 1.
     */
    public function sample(float $rate): self
    {
        $rate >= 0.0 && $rate <= 1.0 or throw new \InvalidArgumentException('The sample rate must be between 0 and 1.');
        $this->settings->sample = $rate;
        return $this;
    }

    /**
     * Writes the profile to a file, creating missing directories.
     *
     * Without a format, it is guessed from the file name: `*.speedscope.json` is Speedscope;
     * `*.txt`, `*.folded` and `*.collapsed` are Collapsed; `*.xhprof` is XHProf;
     * anything else gets the native format of the profiler.
     * The format is preferred: a profiler that can't produce it writes its native format,
     * as XHProf writes no Speedscope.
     *
     * @param non-empty-string $path
     */
    public function toFile(string $path, ?Format $format = null): self
    {
        $format ??= $this->guessFormat($path);

        $this->settings->destinations[] = static function (Profile $profile) use ($path, $format): void {
            $formats = $profile->formats();
            $data = $profile->encode(\in_array($format, $formats, true) ? $format : $formats[0]);
            $dir = \dirname($path);
            \is_dir($dir) or @\mkdir($dir, 0777, true);
            @\file_put_contents($path, $data, \LOCK_EX) === false
            and throw new \RuntimeException(\sprintf('Unable to write the profile to `%s`.', $path));
        };

        return $this;
    }

    /**
     * Sends the profile to Trap over HTTP. Delivery errors are logged.
     *
     * @param non-empty-string|null $host Defaults to the `TRAP_PROFILER_HOST` env variable, then `127.0.0.1`.
     * @param int|null $port From 1 to 65535. Defaults to the `TRAP_PROFILER_PORT` env variable, then `9912`.
     */
    public function toTrap(?string $host = null, ?int $port = null): self
    {
        $port === null || ($port >= 1 && $port <= 65535)
        or throw new \InvalidArgumentException('The port must be between 1 and 65535.');

        $this->settings->destinations[] = static function (Profile $profile) use ($host, $port): void {
            TrapSender::create($host, $port)->send($profile);
        };

        return $this;
    }

    /**
     * Sends the profile to any HTTP server in the chosen format; by default, XHProf to a local Buggregator Server.
     * The format is preferred: a profiler that can't produce it sends its native format. Delivery errors are logged.
     *
     * XHProf goes as the Buggregator `/api/profiler/store` JSON with the metadata inside.
     * Speedscope (`application/json`) and Collapsed (`text/plain`) go as the bare document,
     * with the metadata added to the URL query: `app_name`, `hostname`, `name`, `clock`, `period` (seconds)
     * and `tags[...]`.
     *
     * ```php
     * ->toServer('http://profiler@buggregator:8000/api/profiler/store')
     * ->toServer('https://example.com/profiles?project=shop', Format::Speedscope, ['X-Token' => $token])
     * ```
     *
     * @param non-empty-string|null $url An http or https URL; userinfo is sent as Basic authorization.
     *        Defaults to the `TRAP_PROFILER_SERVER` env variable, then `http://127.0.0.1:8000/api/profiler/store`.
     *        Bytes above ASCII in the path and query are percent-encoded.
     * @param array<non-empty-string, string|null> $headers Merged over `X-Buggregator-Event: profiler`,
     *        `Content-Type` and, with userinfo, `Authorization`; names are case-insensitive and a null value
     *        removes the header. `Host`, `Content-Length`, `Connection`, `Transfer-Encoding` and `Expect`
     *        are not allowed.
     *
     * @throws \InvalidArgumentException On an invalid URL or header:
     *         a URL with spaces or control characters (encode them), a header name that is not an RFC 9110 token,
     *         or a value with control characters other than the tab.
     */
    public function toServer(
        ?string $url = null,
        Format $format = Format::XHProf,
        array $headers = [],
    ): self {
        $sender = ServerSender::create($url, $format, $headers);

        $this->settings->destinations[] = static function (Profile $profile) use ($sender): void {
            $sender->send($profile);
        };

        return $this;
    }

    /**
     * Passes the profile to a custom destination.
     *
     * ```php
     * ->to(static fn(Profile $p) => $api->upload($p->encode(Format::Speedscope)))
     * ```
     *
     * @param callable(Profile): mixed $destination
     */
    public function to(callable $destination): self
    {
        $this->settings->destinations[] = static function (Profile $profile) use ($destination): void {
            $destination($profile);
        };

        return $this;
    }

    /**
     * Profiles the callable, delivers the profile and returns the callable result.
     *
     * If the callable throws, the profile is delivered and the exception is rethrown.
     * Errors of the profiler and of the destinations are logged, never thrown.
     * While another profile runs, the callable just runs.
     *
     * @template T
     *
     * @param callable(): T $callable
     *
     * @return T
     */
    public function run(callable $callable): mixed
    {
        $session = Session::open(clone $this->settings);

        try {
            $result = $callable();
        } catch (\Throwable $throwable) {
            $session->finish();
            throw $throwable;
        }

        $session->stop();
        return $result;
    }

    /**
     * Starts profiling until {@see Session::stop()}.
     *
     * Call `stop()` in a `finally` block: a session kept alive, e.g. by an exception trace, keeps the profiler busy,
     * and the profiles started meanwhile are skipped. While another profile runs, the session does nothing.
     */
    public function start(): Session
    {
        return Session::open(clone $this->settings);
    }

    private function detect(): ?Driver
    {
        foreach ([new Excimer(), new XHProf()] as $driver) {
            if ($driver->isAvailable()) {
                return $driver;
            }
        }

        return null;
    }

    private function guessFormat(string $path): ?Format
    {
        $name = \strtolower(\basename($path));

        return match (true) {
            \str_ends_with($name, '.speedscope.json') => Format::Speedscope,
            \str_ends_with($name, '.txt'),
            \str_ends_with($name, '.folded'),
            \str_ends_with($name, '.collapsed') => Format::Collapsed,
            \str_ends_with($name, '.xhprof') => Format::XHProf,
            default => null,
        };
    }
}
