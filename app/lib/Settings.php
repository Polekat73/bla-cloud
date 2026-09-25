<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Admin-changeable settings, stored in the database (so config.php can stay read-only).
 * Falls back to config.php values, then to built-in defaults.
 */
final class Settings
{
    public const DEFAULTS = [
        // Security
        'require_2fa_all'      => false,
        // Storage
        'trash_days'           => 30,
        'versions_keep'        => 10,
        'versions_max_days'    => 180,
        'default_quota_gb'     => 0,      // 0 = unlimited
        // Sharing
        'links_enabled'        => true,
        'links_require_password' => false,
        'links_max_days'       => 0,      // 0 = no maximum
        'links_default_days'   => 14,     // suggested expiry in the share dialog (0 = none)
        'share_notify'         => true,   // email people when something is shared with them
        // Email
        'mail_mode'            => 'off',  // off | php | smtp
        'mail_from'            => '',
        'mail_from_name'       => 'BLA-Cloud',
        'smtp_host'            => '',
        'smtp_port'            => 587,
        'smtp_security'        => 'starttls', // starttls | ssl | none
        'smtp_user'            => '',
        'smtp_pass'            => '',     // encrypted
        // General
        'base_url'             => '',     // e.g. https://cloud.example.com — used in emails
        // Backups
        'backup_enabled'       => false,
        'backup_dir'           => '',
        'backup_frequency'     => 'daily', // daily | weekly
        'backup_retention'     => 7,
        'backup_passphrase_enc' => '',    // encrypted; the plain passphrase is never stored
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = [];
            try {
                $row = Database::one("SELECT meta_value FROM bla_meta WHERE meta_key = 'settings'");
                $stored = $row ? (array) json_decode((string) $row['meta_value'], true) : [];
            } catch (\Throwable) {
                $stored = [];
            }
            $merged = self::DEFAULTS;
            foreach (self::DEFAULTS as $k => $_) {
                if (array_key_exists($k, $stored)) {
                    $merged[$k] = $stored[$k];
                } elseif (Config::get($k) !== null) {
                    $merged[$k] = Config::get($k); // older installs kept some of these in config.php
                }
            }
            self::$cache = $merged;
        }
        return self::$cache;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function save(array $values): void
    {
        $current = self::all();
        foreach ($values as $k => $v) {
            if (!array_key_exists($k, self::DEFAULTS)) {
                continue;
            }
            // Keep the type of the default.
            $d = self::DEFAULTS[$k];
            $current[$k] = match (true) {
                is_bool($d) => (bool) $v,
                is_int($d)  => (int) $v,
                default     => (string) $v,
            };
        }
        $sql = Database::driver() === 'mysql'
            ? 'REPLACE INTO bla_meta (meta_key, meta_value) VALUES (?, ?)'
            : 'INSERT OR REPLACE INTO bla_meta (meta_key, meta_value) VALUES (?, ?)';
        Database::run($sql, ['settings', json_encode($current)]);
        self::$cache = $current;
    }

    /** Public base URL for links in emails (setting, else the current request). */
    public static function baseUrl(): string
    {
        $b = rtrim((string) self::get('base_url'), '/');
        if ($b !== '') {
            return $b;
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
            $host = 'localhost';
        }
        return (Request::isHttps() ? 'https://' : 'http://') . $host . Request::basePath();
    }

    public static function absoluteUrl(string $route, array $query = []): string
    {
        return self::baseUrl() . '/index.php?' . http_build_query(['r' => $route] + $query);
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }
}
