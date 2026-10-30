<?php

namespace phasync\Tui\Internal;

/**
 * @internal The check that the installed fiber-interop/api has what the library uses, run when a
 * program starts (`Component::start()`, `TerminalDevice::attach()`), so an old version gives a
 * clear message instead of an undefined function deep inside a coroutine.
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
}
