<?php
declare(strict_types=1);

namespace BlaCloud;

/** Builds a zip of selected files/folders in the user's temp folder, then streams it. */
final class Zipper
{
    public const MAX_BYTES = 4 * 1024 ** 3;      // 4 GB per zip
    public const MAX_FILES = 20000;
    private const STORED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'mp4', 'mov', 'm4v', 'webm', 'mkv',
                            'mp3', 'm4a', 'aac', 'ogg', 'opus', 'zip', 'gz', '7z', 'rar', 'docx', 'xlsx', 'pptx', 'pdf'];

    public static function available(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /** @param string[] $paths relative paths (all in the same folder, or anywhere) */
    public static function build(Storage $fs, array $paths): array
    {
        if (!self::available()) {
            throw new StorageException('Zip downloads need the PHP "zip" extension. Ask your host to enable it.');
        }
        $entries = [];
        $bytes = 0;
        foreach ($paths as $p) {
            $rel = Storage::normalize($p);
            if ($rel === '') {
                continue;
            }
            $abs = $fs->abs($rel);
            $base = basename($rel);
            if (is_file($abs)) {
                $entries[] = [$abs, $base];
                $bytes += (int) filesize($abs);
                continue;
            }
            $entries[] = [null, $base . '/'];
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $f) {
                if ($f->isLink() || Storage::isReserved($f->getFilename())) {
                    continue;
                }
                $inner = $base . str_replace('\\', '/', substr($f->getPathname(), strlen($abs)));
                if ($f->isDir()) {
                    $entries[] = [null, $inner . '/'];
                } else {
                    $entries[] = [$f->getPathname(), $inner];
                    $bytes += $f->getSize();
                }
                if (count($entries) > self::MAX_FILES) {
                    throw new StorageException('Too many files for one zip. Please download smaller folders.');
                }
            }
        }
        if (!$entries) {
            throw new StorageException('Nothing to download.');
        }
        if ($bytes > self::MAX_BYTES) {
            throw new StorageException('That selection is over 4 GB. Please download smaller parts.');
        }
        $free = @disk_free_space($fs->root());
        if ($free !== false && $bytes > $free - 100 * 1024 * 1024) {
            throw new StorageException('Not enough free space on the server to build the zip.');
        }
        @set_time_limit(0);
        $tmp = $fs->internalDir('tmp') . '/zip-' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new StorageException('Could not create the zip file.');
        }
        // ZipArchive reads each addFile() source lazily, only when close() runs — so a decrypted
        // temp copy has to survive until after close(), not be cleaned up as each entry is added.
        $decryptedTemps = [];
        try {
            foreach ($entries as [$abs, $name]) {
                if ($abs === null) {
                    $zip->addEmptyDir(rtrim($name, '/'));
                    continue;
                }
                $srcPath = $abs;
                if (Encryption::isEncryptedFile($abs)) {
                    $srcPath = Encryption::decryptToScratch($abs);
                    $decryptedTemps[] = $srcPath;
                }
                $zip->addFile($srcPath, $name);
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, self::STORED, true) && method_exists($zip, 'setCompressionName')) {
                    $zip->setCompressionName($name, \ZipArchive::CM_STORE); // already compressed: just store
                }
            }
            if (!$zip->close()) {
                @unlink($tmp);
                throw new StorageException('Could not finish the zip file.');
            }
        } finally {
            foreach ($decryptedTemps as $t) {
                @unlink($t);
            }
        }
        $label = count($paths) === 1 ? basename(Storage::normalize($paths[0])) : 'Haven files';
        return [$tmp, $label . '.zip'];
    }

    public static function send(string $tmp, string $name): never
    {
        register_shutdown_function(static fn () => @unlink($tmp));
        FileSender::download($tmp, $name);
    }
}
