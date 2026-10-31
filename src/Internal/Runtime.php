<?php

namespace phasync\Tui\Internal;

/**
 * @internal The check that the installed fiber-interop/api has what the library uses, run when a
 * program starts (`Component::start()`, `TerminalDevice::attach()`), so an old version gives a
 * clear message instead of an undefined function deep inside a coroutine; and the watcher that
 * ends a program when the code that started it fails (`watch()`), or the warning where the
 * installed packages cannot (fiber-interop/api 0.3.1 and fiber-interop/phasync 0.4.1, the tags
 * on Packagist until the fix is tagged).
 */
final class Runtime
{
    /** The oldest fiber-interop/api the library works with. */
    public const REQUIRED = '0.3.1';

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
        if ($tooOld || !\function_exists('fiber\current') || !method_exists(\fiber\Deferred::class, 'resolve')) {
            throw new \RuntimeException(sprintf(
                'phasync/tui needs fiber-interop/api %s or later, but %s is installed: run `composer require fiber-interop/api:^%s`',
                self::REQUIRED,
                $installed ?? 'an older version',
                self::REQUIRED,
            ));
        }
        self::$checked = self::$checked || $asked;
    }

    /**
     * Versions of the packages that cannot await a task's `fiber\current()` handle from a task
     * started inside it: fiber-interop/api 0.3.1 and older, and fiber-interop/phasync 0.4.1 and
     * older (that adapter runs the awaited task's body a second time). Fixed on the main
     * branches (api 4dc3d41, phasync adapter 8445790), not tagged yet.
     */
    private const CANNOT_AWAIT_STARTER = ['fiber-interop/api' => '0.3.1', 'fiber-interop/phasync' => '0.4.1'];

    /** The first versions that can (see CANNOT_AWAIT_STARTER), for the warning. */
    private const CAN_AWAIT_STARTER = ['fiber-interop/api' => '0.3.2', 'fiber-interop/phasync' => '0.4.2'];

    /** For tests: force whether watch() watches (null: decide from the installed versions). */
    public static ?bool $watching = null;
    private static ?bool $canWatch = null;
    /** Inside a runner that owns the program it starts (Tui::run(), Tui::inline()): nothing to watch. */
    private static int $owned = 0;
    private static bool $warned = false;
    /** @var (\Closure(string): void)|null for tests: where the warning goes (default: STDERR) */
    public static ?\Closure $warn = null;

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
     * happens when the caller returns normally, when there is no current task, or when the
     * installed packages cannot do it (see CANNOT_AWAIT_STARTER).
     *
     * @param \Closure(\Throwable): void $onFailure
     */
    public static function watch(\Closure $onFailure): void
    {
        $starter = \fiber\current();
        if ($starter === null || self::$owned > 0) {
            return;
        }
        if (!(self::$watching ?? self::$canWatch ??= self::canWatch())) {
            self::warnOnce();
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

    /**
     * The installed packages cannot watch the code that started a program: say so, once per
     * process, on stderr, before the program takes the terminal over. That code failing would
     * leave `fiber\run()` waiting for the program forever, with nothing on the screen to tell.
     */
    private static function warnOnce(): void
    {
        if (self::$warned) {
            return;
        }
        self::$warned = true;
        $installed = [];
        foreach (array_keys(self::CAN_AWAIT_STARTER) as $package) {
            if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled($package)) {
                $installed[] = $package . ' ' . \Composer\InstalledVersions::getPrettyVersion($package);
            }
        }
        $needed = array_map(fn(string $package, string $version) => "$package >= $version", array_keys(self::CAN_AWAIT_STARTER), self::CAN_AWAIT_STARTER);
        $line = 'phasync/tui: with ' . ($installed === [] ? 'the fiber-interop packages' : implode(' and ', $installed))
            . ' installed, an exception in the fiber\run() closure that started this program cannot end it: fiber\run()'
            . ' would wait forever (Tui::run(), Tui::inline() and Tui::go() are not affected). Install ' . implode(', ', $needed) . ".\n";
        if (self::$warn !== null) {
            (self::$warn)($line);
        } elseif (\defined('STDERR')) {
            fwrite(\STDERR, $line);
        }
    }

    private static function canWatch(): bool
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return false;
        }
        foreach (self::CANNOT_AWAIT_STARTER as $package => $last) {
            if (!\Composer\InstalledVersions::isInstalled($package)) {
                if ($package === 'fiber-interop/api') {
                    return false;
                }
                continue;
            }
            $version = ltrim((string) \Composer\InstalledVersions::getPrettyVersion($package), 'vV');
            if (preg_match('/^\d+(\.\d+)*$/', $version) && version_compare($version, $last, '<=')) {
                return false;
            }
        }
        return true;
    }
}
