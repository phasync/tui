<?php

namespace phasync\Tui\Internal;

/**
 * @internal The check that the installed fiber-interop/api has what the library uses, run when a
 * program starts (`Component::start()`, `TerminalDevice::attach()`), so an old version gives a
 * clear message instead of an undefined function deep inside a coroutine; and the watcher that
 * ends a program when the code that started it fails (`watch()`).
 */
final class Runtime
{
    /** The oldest fiber-interop/api the library works with. */
    public const REQUIRED = '0.3.2';

    private static bool $checked = false;

    /**
     * @param string|null $installed the installed version (default: asked from Composer); for tests
     * @throws \RuntimeException when fiber-interop/api is missing or older than required
     */
    public static function check(?string $installed = null): void
    {
        $asked = $installed === null;
        if (self::$checked && $asked) {
            return;
        }
        if ($asked && class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled('fiber-interop/api')) {
            $installed = \Composer\InstalledVersions::getPrettyVersion('fiber-interop/api');
        }
        $version = $installed === null ? null : ltrim($installed, 'vV');
        $tooOld = $version !== null && preg_match('/^\d+(\.\d+)*$/', $version) && version_compare($version, self::REQUIRED, '<');
        if ($tooOld || !\function_exists('fiber\cancel') || !method_exists(\fiber\Deferred::class, 'resolve')) {
            throw new \RuntimeException(sprintf(
                'phasync/tui needs fiber-interop/api %s or later, but %s is installed: run `composer require fiber-interop/api:^%s`',
                self::REQUIRED,
                $installed ?? 'an older version',
                self::REQUIRED,
            ));
        }
        self::$checked = self::$checked || $asked;
    }

    /** Inside a runner that owns the program it starts (Tui::run(), Tui::inline()): nothing to watch. */
    private static int $owned = 0;

    /**
     * Run $start as a runner that owns the program it starts: it waits for the program and
     * ends it when its own code fails (`Tui::run()`), so there is no starter to watch and no
     * warning to give.
     *
     * @template T
     * @param \Closure(): T $start
     * @return T
     */
    public static function owned(\Closure $start): mixed
    {
        self::$owned++;
        try {
            return $start();
        } finally {
            self::$owned--;
        }
    }

    /**
     * Run $onFailure when the task calling this ends with an exception, and let the exception
     * go on to `fiber\run()` as before: how `attach()` and `start()` end a program whose own
     * code failed, instead of the program's coroutines keeping `fiber\run()` from ever
     * returning with it. A watcher task awaits the caller's `fiber\current()` handle; nothing
     * happens when the caller returns normally or when there is no current task.
     *
     * @param \Closure(\Throwable): void $onFailure
     */
    public static function watch(\Closure $onFailure): void
    {
        $starter = \fiber\current();
        if ($starter === null || self::$owned > 0) {
            return;
        }
        \fiber\go(static function () use ($starter, $onFailure): void {
            try {
                \fiber\await($starter);
            } catch (\Throwable $e) {
                try {
                    $onFailure($e);
                } finally {
                    throw $e;       // (awaited here, so fiber\run() sees it through this task)
                }
            }
        });
    }
}
