<?php
declare(strict_types=1);

namespace BlaCloud;

final class View
{
    /** Render app/views/{name}.php inside the layout. */
    public static function render(string $name, array $vars = [], string $layout = 'layout'): void
    {
        Security::sendHeaders();
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $content = self::capture($name, $vars);
        echo self::capture($layout, $vars + ['content' => $content]);
    }

    public static function capture(string $name, array $vars = []): string
    {
        if (!preg_match('~^[a-z0-9_/-]+$~', $name)) {
            throw new \RuntimeException('View not found: ' . $name);
        }
        return self::captureFile(BLA_APP . '/views/' . $name . '.php', $vars);
    }

    /** Like capture(), but for a view file outside app/views — used by apps, whose views live alongside their own code. */
    public static function captureFile(string $file, array $vars = []): string
    {
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $file);
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /** Render {$appDir}/views/{$name}.php inside the main layout — an app's equivalent of render(). */
    public static function renderApp(string $appDir, string $name, array $vars = [], string $layout = 'layout'): void
    {
        Security::sendHeaders();
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $content = self::captureFile(rtrim($appDir, '/') . '/views/' . $name . '.php', $vars);
        echo self::capture($layout, $vars + ['content' => $content]);
    }

    public static function json(array $data, int $status = 200): never
    {
        Security::sendHeaders();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function redirect(string $route, array $query = []): never
    {
        header('Location: ' . self::url($route, $query), true, 303);
        exit;
    }

    public static function url(string $route, array $query = []): string
    {
        $q = $route === '' ? $query : ['r' => $route] + $query;
        return Request::basePath() . '/index.php' . ($q ? '?' . http_build_query($q) : '');
    }

    public static function asset(string $path): string
    {
        $file = BLA_ROOT . '/assets/' . $path;
        $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : BLA_VERSION;
        return Request::basePath() . '/assets/' . $path . '?v=' . $v;
    }

    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $bytes : number_format($bytes, $bytes < 10 ? 1 : 0)) . ' ' . $units[$i];
    }
}
