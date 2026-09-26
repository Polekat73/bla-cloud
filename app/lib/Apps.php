<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Loads installed apps from app/apps/*\/manifest.php and tracks which are enabled.
 *
 * An app is a self-contained folder (its own controller, views and namespace segment —
 * see app/apps/README.md for the manifest format). Installing one is manual file placement
 * (unzip into app/apps/, same as installing Haven itself); this class only discovers what's
 * already on disk and lets an admin turn each one on or off. A disabled app's routes 404 and its
 * nav link disappears, but nothing about it is uninstalled — its data (if any) is untouched.
 */
final class Apps
{
    private const DIR = BLA_ROOT . '/app/apps';

    private static ?array $manifests = null;
    private static ?array $enabled = null;

    /** All discovered apps, keyed by id, sorted by nav order then name. Cached for the request. */
    public static function all(): array
    {
        if (self::$manifests !== null) {
            return self::$manifests;
        }
        $found = [];
        foreach (glob(self::DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $file = $dir . '/manifest.php';
            if (!is_file($file)) {
                continue;
            }
            $m = require $file;
            if (!is_array($m) || !isset($m['id'], $m['name'], $m['routes']) || $m['id'] !== basename($dir)) {
                error_log("[Haven] skipping invalid app manifest: $file");
                continue;
            }
            $m['dir'] = $dir;
            $m += ['description' => '', 'version' => '', 'icon' => 'grid', 'nav' => null, 'default_enabled' => true];
            $found[$m['id']] = $m;
        }
        uasort($found, static fn ($a, $b) => (($a['nav']['order'] ?? 999) <=> ($b['nav']['order'] ?? 999)) ?: strcmp($a['name'], $b['name']));
        return self::$manifests = $found;
    }

    public static function isEnabled(string $id): bool
    {
        $apps = self::all();
        if (!isset($apps[$id])) {
            return false;
        }
        $state = self::enabledState();
        return array_key_exists($id, $state) ? $state[$id] : (bool) $apps[$id]['default_enabled'];
    }

    /** Enabled apps only, in the same order as all(). */
    public static function enabledApps(): array
    {
        return array_filter(self::all(), static fn ($m) => self::isEnabled($m['id']));
    }

    public static function setEnabled(string $id, bool $on): void
    {
        if (!isset(self::all()[$id])) {
            throw new StorageException('That app does not exist.');
        }
        $state = self::enabledState();
        $state[$id] = $on;
        Database::run(
            Database::driver() === 'mysql'
                ? 'REPLACE INTO bla_meta (meta_key, meta_value) VALUES (?, ?)'
                : 'INSERT OR REPLACE INTO bla_meta (meta_key, meta_value) VALUES (?, ?)',
            ['apps_enabled', json_encode($state)]
        );
        self::$enabled = $state;
    }

    /** route => [controller, method], merged across every enabled app. */
    public static function routes(): array
    {
        $routes = [];
        foreach (self::enabledApps() as $m) {
            $routes += $m['routes'];
        }
        return $routes;
    }

    /** Sidebar entries for enabled apps that declare one: ['route', 'label', 'icon']. */
    public static function navItems(): array
    {
        $items = [];
        foreach (self::enabledApps() as $m) {
            if ($m['nav']) {
                $items[] = ['route' => $m['nav']['route'], 'label' => $m['nav']['label'], 'icon' => $m['icon']];
            }
        }
        return $items;
    }

    private static function enabledState(): array
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }
        try {
            $row = Database::one("SELECT meta_value FROM bla_meta WHERE meta_key = 'apps_enabled'");
            $state = $row ? (array) json_decode((string) $row['meta_value'], true) : [];
        } catch (\Throwable) {
            $state = [];
        }
        return self::$enabled = $state;
    }

    /** Tests only: forces a re-scan of app/apps and re-read of the enabled state. */
    public static function resetCache(): void
    {
        self::$manifests = null;
        self::$enabled = null;
    }
}
