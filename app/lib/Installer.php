<?php
declare(strict_types=1);

namespace BlaCloud;

final class Installer
{
    /** Server readiness checks shown on the first wizard screen. */
    public static function checks(): array
    {
        $c = [];
        $add = static function (string $label, string $status, string $detail) use (&$c): void {
            $c[] = compact('label', 'status', 'detail');
        };

        $add('PHP version', PHP_VERSION_ID >= 80200 ? 'ok' : 'fail', 'Running PHP ' . PHP_VERSION . ' (8.2 or newer needed)');

        $sqlite = extension_loaded('pdo_sqlite');
        $mysql  = extension_loaded('pdo_mysql');
        $add('Database support', ($sqlite || $mysql) ? 'ok' : 'fail',
            implode(' and ', array_filter([$sqlite ? 'SQLite' : '', $mysql ? 'MySQL/MariaDB' : ''])) ?: 'Needs the pdo_sqlite or pdo_mysql extension');

        foreach ([
            'sodium'   => ['fail', 'Encryption (libsodium)'],
            'mbstring' => ['fail', 'Text handling (mbstring)'],
            'fileinfo' => ['warn', 'File type detection (fileinfo)'],
            'gd'       => ['warn', 'Image thumbnails (GD) — used in a later update'],
            'zip'      => ['warn', 'Zip archives — used for backups in a later update'],
        ] as $ext => [$ifMissing, $label]) {
            $ok = extension_loaded($ext) || ($ext === 'gd' && extension_loaded('imagick'));
            $add($label, $ok ? 'ok' : $ifMissing, $ok ? 'Available' : "The PHP \"$ext\" extension is missing");
        }

        $cfgDir = BLA_ROOT . '/config';
        $add('Config folder writable', is_writable($cfgDir) ? 'ok' : 'fail',
            is_writable($cfgDir) ? 'Setup can save your settings' : 'Make the "config" folder writable (permissions 750 or 770)');

        $https = Request::isHttps();
        $add('Secure connection (HTTPS)', $https ? 'ok' : (Request::isLocalhost() ? 'warn' : 'fail'),
            $https ? 'This site is using HTTPS'
                : (Request::isLocalhost() ? 'Fine for testing on this computer. Use HTTPS before going online.'
                    : 'Turn on HTTPS (free with Let\'s Encrypt in most hosting panels) before continuing.'));

        $add('64-bit PHP', PHP_INT_SIZE >= 8 ? 'ok' : 'warn',
            PHP_INT_SIZE >= 8 ? 'Large files (over 2 GB) supported' : 'Files over 2 GB may not work');

        $mem = self::iniBytes((string) ini_get('memory_limit'));
        $add('Memory limit', ($mem < 0 || $mem >= 128 * 1024 * 1024) ? 'ok' : 'warn',
            $mem < 0 ? 'No limit set' : 'memory_limit = ' . ini_get('memory_limit') . ' (128M or more recommended)');

        $add('Upload size per request', 'ok',
            'upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size')
            . '. Large files are sent in pieces, so this is fine.');

        return $c;
    }

    public static function hasBlockingProblem(array $checks): bool
    {
        foreach ($checks as $c) {
            if ($c['status'] === 'fail' && !($c['label'] === 'Secure connection (HTTPS)')) {
                return true;
            }
        }
        return false;
    }

    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** Largest request body we can safely send in one go (for chunked uploads). */
    public static function chunkSize(): int
    {
        $limits = array_filter([
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        ], static fn ($x) => $x > 0);
        $max = $limits ? min($limits) : 64 * 1024 * 1024;
        // Leave headroom for form fields; cap at 32 MB, never below 512 KB.
        return (int) max(512 * 1024, min(32 * 1024 * 1024, $max - 64 * 1024));
    }

    /** Suggest a data folder outside the public web folder when possible. */
    public static function suggestedDataDir(): string
    {
        $outside = dirname(BLA_ROOT) . '/bla-cloud-data';
        if (is_dir($outside) ? is_writable($outside) : @is_writable(dirname(BLA_ROOT))) {
            if (self::openBasedirAllows($outside)) {
                return $outside;
            }
        }
        return BLA_ROOT . '/data';
    }

    private static function openBasedirAllows(string $path): bool
    {
        $ob = (string) ini_get('open_basedir');
        if ($ob === '') {
            return true;
        }
        foreach (explode(PATH_SEPARATOR, $ob) as $allowed) {
            if ($allowed !== '' && str_starts_with($path, rtrim($allowed, '/'))) {
                return true;
            }
        }
        return false;
    }

    public static function isInsideWebRoot(string $dir): bool
    {
        $doc = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
        $real = realpath($dir) ?: $dir;
        return $doc !== '' && str_starts_with($real, $doc);
    }

    /** Create and lock down the data folder. Returns the absolute path or throws. */
    public static function prepareDataDir(string $dir): string
    {
        $dir = rtrim(trim($dir), '/\\');
        if ($dir === '' || !preg_match('~^(/|[A-Za-z]:[\\\\/])~', $dir)) {
            throw new \InvalidArgumentException('Please enter a full folder path (starting with / on Linux or a drive letter on Windows).');
        }
        if (str_contains($dir, '..')) {
            throw new \InvalidArgumentException('The folder path cannot contain "..".');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new \InvalidArgumentException('Could not create that folder. Create it yourself (or pick another) and make sure PHP can write to it.');
        }
        if (!is_writable($dir)) {
            throw new \InvalidArgumentException('PHP cannot write to that folder. Change its permissions (e.g. 750) and try again.');
        }
        $real = (string) realpath($dir);
        if ($real === realpath(BLA_ROOT) || str_starts_with(realpath(BLA_ROOT) . '/', $real . '/')) {
            throw new \InvalidArgumentException('The data folder cannot be the BLA-Cloud program folder itself or one of its parents.');
        }
        // Defence in depth if the folder is reachable from the web.
        @file_put_contents($real . '/.htaccess', "# BLA-Cloud: never serve anything from here\nRequire all denied\nDeny from all\nOptions -Indexes -ExecCGI\n<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n");
        @file_put_contents($real . '/index.html', '');
        @file_put_contents($real . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
        @mkdir($real . '/users', 0750);
        return $real;
    }

    /**
     * Run the installation. $s holds the wizard answers. Returns the new admin user's id.
     */
    public static function install(array $s): int
    {
        if (Config::isInstalled()) {
            throw new \RuntimeException('BLA-Cloud is already installed.');
        }
        $dataDir = self::prepareDataDir($s['data_dir']);

        $db = $s['db'];
        if ($db['driver'] === 'sqlite') {
            $db = ['driver' => 'sqlite', 'path' => $dataDir . '/bla-cloud.sqlite'];
        }
        $pdo = Database::connectWith($db);
        if (Schema::exists($pdo)) {
            throw new \RuntimeException('This database already contains a BLA-Cloud installation. Use a new, empty database (or restore your old config file).');
        }
        try {
            Schema::create($pdo, $db['driver']);
            return self::finish($s, $db, $dataDir, $pdo);
        } catch (\Throwable $e) {
            // Roll back so the wizard can simply be run again.
            @unlink(Config::path());
            if ($db['driver'] === 'sqlite') {
                $pdo = null;
                foreach (['', '-wal', '-shm'] as $sfx) {
                    @unlink($db['path'] . $sfx);
                }
            }
            throw $e;
        }
    }

    private static function finish(array $s, array $db, string $dataDir, \PDO $pdo): int
    {
        $config = [
            'installed'        => false, // flipped to true once everything below succeeds
            'version'          => BLA_VERSION,
            'schema_version'   => Schema::VERSION,
            'instance_id'      => 'bla' . bin2hex(random_bytes(5)),
            'instance_name'    => $s['instance_name'] ?: 'BLA-Cloud',
            'app_key'          => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            'db'               => $db,
            'data_dir'         => $dataDir,
            'trusted_proxies'  => $s['trusted_proxies'] ?? [],
            'require_2fa_all'  => false,
            'installed_at'     => gmdate('c'),
        ];
        Config::write($config);

        $pdo->prepare('INSERT INTO bla_users (username, display_name, email, password_hash, is_admin, created_at) VALUES (?, ?, ?, ?, 1, ?)')
            ->execute([$s['username'], $s['display_name'] ?: $s['username'], $s['email'], Security::hashPassword($s['password']), Database::now()]);
        $adminId = (int) $pdo->lastInsertId();
        Dav\Provisioning::seedDefaults($adminId);

        // Remember the public address for links in emails (protects reset emails from spoofed Host headers).
        Settings::save(['base_url' => Settings::baseUrl()]);

        $config['installed'] = true;
        Config::write($config);
        Audit::log($adminId, 'install.completed', 'BLA-Cloud ' . BLA_VERSION);
        return $adminId;
    }
}
