<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Config;
use BlaCloud\Database;
use BlaCloud\Installer;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\View;

/** The first-run setup wizard. Only reachable while BLA-Cloud is not installed yet. */
final class SetupController
{
    private const STEPS = ['welcome', 'database', 'storage', 'account'];

    public function handle(): void
    {
        $step = Request::get('step', 'welcome');
        if (!in_array($step, self::STEPS, true)) {
            $step = 'welcome';
        }
        $_SESSION['setup'] ??= [];

        // Don't allow skipping ahead.
        $reached = $_SESSION['setup']['reached'] ?? 0;
        $idx = array_search($step, self::STEPS, true);
        if ($idx > $reached) {
            $this->go(self::STEPS[$reached]);
        }

        $errors = [];
        if (Request::isPost()) {
            Security::requireCsrf();
            $errors = $this->{'save' . ucfirst($step)}();
            if (!$errors) {
                $next = $idx + 1;
                if ($next >= count(self::STEPS)) {
                    // Installed: from now on the wizard is locked, so show the finish page right here.
                    $this->render('done', []);
                    return;
                }
                $_SESSION['setup']['reached'] = max($reached, $next);
                $this->go(self::STEPS[$next]);
            }
        }
        $this->render($step, ['errors' => $errors, 'setup' => $_SESSION['setup']]);
    }

    private function render(string $step, array $vars): void
    {
        View::render('setup/' . $step, $vars + [
            'title' => 'Set up BLA-Cloud',
            'step'  => $step,
            'steps' => self::STEPS,
        ], 'layout-setup');
    }

    private function go(string $step): never
    {
        header('Location: ' . Request::basePath() . '/index.php?step=' . $step, true, 303);
        exit;
    }

    // ---------- Step handlers (return list of error messages) ----------

    private function saveWelcome(): array
    {
        if (Installer::hasBlockingProblem(Installer::checks())) {
            return ['Please fix the items marked in red first, then reload this page.'];
        }
        return [];
    }

    private function saveDatabase(): array
    {
        $driver = Request::post('driver') === 'mysql' ? 'mysql' : 'sqlite';
        if ($driver === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                return ['SQLite is not available on this server. Please choose MySQL/MariaDB.'];
            }
            $_SESSION['setup']['db'] = ['driver' => 'sqlite'];
            return [];
        }
        $db = [
            'driver'   => 'mysql',
            'host'     => trim(Request::post('host', 'localhost')) ?: 'localhost',
            'port'     => (int) (Request::post('port', '3306') ?: 3306),
            'name'     => trim(Request::post('name')),
            'user'     => trim(Request::post('user')),
            'password' => Request::post('password'),
        ];
        $_SESSION['setup']['db_form'] = array_diff_key($db, ['password' => 1]);
        if ($db['name'] === '' || $db['user'] === '') {
            return ['Please fill in the database name and user.'];
        }
        if (!preg_match('/^[A-Za-z0-9_\-.]+$/', $db['host']) || $db['port'] < 1 || $db['port'] > 65535) {
            return ['Please check the database host and port.'];
        }
        try {
            $pdo = Database::connectWith($db);
            $pdo->query('SELECT 1');
        } catch (\PDOException $e) {
            error_log('[BLA-Cloud] setup db test: ' . $e->getMessage());
            return ['Could not connect to the database. Double-check the details from your hosting panel. (' . self::friendlyDbError($e) . ')'];
        }
        $_SESSION['setup']['db'] = $db;
        return [];
    }

    private static function friendlyDbError(\PDOException $e): string
    {
        $m = $e->getMessage();
        return match (true) {
            str_contains($m, 'Access denied')     => 'wrong user name or password',
            str_contains($m, 'Unknown database')  => 'that database does not exist yet — create it in your hosting panel',
            str_contains($m, 'getaddrinfo'), str_contains($m, 'Connection refused') => 'cannot reach the database server',
            default => 'error ' . $e->getCode(),
        };
    }

    private function saveStorage(): array
    {
        $dir  = trim(Request::post('data_dir'));
        $name = trim(Request::post('instance_name', 'BLA-Cloud'));
        $_SESSION['setup']['data_dir'] = $dir;
        $_SESSION['setup']['instance_name'] = mb_substr($name ?: 'BLA-Cloud', 0, 64);
        try {
            Installer::prepareDataDir($dir);
        } catch (\InvalidArgumentException $e) {
            return [$e->getMessage()];
        }
        $proxy = Request::post('behind_proxy') === '1';
        $_SESSION['setup']['behind_proxy'] = $proxy;
        $_SESSION['setup']['trusted_proxies'] = [];
        if ($proxy) {
            $ips = preg_split('/[\s,]+/', trim(Request::post('proxy_ips'))) ?: [];
            foreach ($ips as $ip) {
                $base = explode('/', $ip)[0];
                if ($ip !== '' && filter_var($base, FILTER_VALIDATE_IP)) {
                    $_SESSION['setup']['trusted_proxies'][] = $ip;
                } elseif ($ip !== '') {
                    return ["\"$ip\" is not a valid IP address or range."];
                }
            }
            if (!$_SESSION['setup']['trusted_proxies']) {
                return ['Enter the IP address of your reverse proxy (or untick the reverse proxy option).'];
            }
        }
        return [];
    }

    private function saveAccount(): array
    {
        $username = trim(Request::post('username'));
        $email    = trim(Request::post('email'));
        $display  = trim(Request::post('display_name'));
        $pass     = Request::post('password');
        $pass2    = Request::post('password_confirm');
        $_SESSION['setup']['account_form'] = compact('username', 'email', 'display');

        $errors = [];
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
            $errors[] = 'Username: 3–32 characters, using letters, numbers, dots, dashes or underscores.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address (or leave it empty).';
        }
        if ($p = Security::passwordProblem($pass, $username)) {
            $errors[] = $p;
        }
        if ($pass !== $pass2) {
            $errors[] = 'The two passwords do not match.';
        }
        if ($errors) {
            return $errors;
        }
        try {
            Installer::install([
                'db'              => $_SESSION['setup']['db'],
                'data_dir'        => $_SESSION['setup']['data_dir'],
                'instance_name'   => $_SESSION['setup']['instance_name'] ?? 'BLA-Cloud',
                'trusted_proxies' => $_SESSION['setup']['trusted_proxies'] ?? [],
                'username'        => $username,
                'display_name'    => mb_substr($display, 0, 128),
                'email'           => $email,
                'password'        => $pass,
            ]);
        } catch (\Throwable $e) {
            error_log('[BLA-Cloud] install failed: ' . $e->getMessage());
            return ['Setup could not finish: ' . $e->getMessage()];
        }
        $_SESSION = [];
        return [];
    }
}
