<?php
declare(strict_types=1);

const BLA_VERSION = '0.3.0';
const BLA_NAME    = 'BLA-Cloud';
define('BLA_ROOT', dirname(__DIR__));
define('BLA_APP', __DIR__);

// Autoloader: BlaCloud\Foo -> app/lib/Foo.php, BlaCloud\Controllers\Bar -> app/controllers/Bar.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'BlaCloud\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    if (str_starts_with($rel, 'Controllers\\')) {
        $file = BLA_APP . '/controllers/' . substr($rel, 12) . '.php';
    } else {
        $file = BLA_APP . '/lib/' . str_replace('\\', '/', $rel) . '.php';
    }
    if (is_file($file)) {
        require $file;
    }
});

require BLA_APP . '/helpers.php';

// Never leak errors to visitors; log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e): void {
    error_log('[BLA-Cloud] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    try {
        BlaCloud\View::render('error', [
            'title'   => 'Something went wrong',
            'message' => 'An unexpected error occurred. The details were written to the server error log.',
        ]);
    } catch (Throwable) {
        echo 'An unexpected error occurred.';
    }
});

set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});
