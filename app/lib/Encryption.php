<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Optional encryption at rest for user files, under a separate passphrase (not the account
 * password, not the app's own encryption key) — so files are still unreadable to anyone who
 * only gets the raw data folder, and still readable with just the passphrase if config.php
 * and the database are lost too.
 *
 * Design, deliberately kept simple (see docs/ROADMAP.md for the full reasoning):
 *  - Turning this on only affects files written from then on. Existing files stay as they are
 *    until "Encrypt existing files now" is run separately.
 *  - Whether a file is encrypted is told apart by an 8-byte marker at the start of the file
 *    (see FileCrypto::hasMagic()) — no database bookkeeping needed, so nothing can drift out
 *    of sync with what's actually on disk.
 *  - File *names* and folder structure are never encrypted, only contents.
 *  - Code that needs the real bytes (thumbnails, zip downloads, serving a download) decrypts to
 *    a short-lived temporary file first rather than using a seekable cipher — simpler and safer,
 *    at the cost of extra I/O for HTTP Range requests (video/audio scrubbing) on large encrypted
 *    files, decrypted here instead of read directly.
 */
final class Encryption
{
    private const MAGIC = "BLAFENC1";

    public static function enabled(): bool
    {
        return (bool) Settings::get('encrypt_enabled') && self::hasKey();
    }

    public static function hasKey(): bool
    {
        return Settings::get('encrypt_passphrase_enc') !== '';
    }

    public static function enable(string $passphrase): void
    {
        if ($problem = Security::passwordProblem($passphrase)) {
            throw new StorageException($problem);
        }
        Settings::save(['encrypt_enabled' => true, 'encrypt_passphrase_enc' => Security::encrypt($passphrase)]);
    }

    /** Stop encrypting new files. Existing encrypted ones, and the passphrase to read them, are kept. */
    public static function pause(): void
    {
        Settings::save(['encrypt_enabled' => false]);
    }

    /** Resume encrypting new files using the passphrase already on file — does not touch it. */
    public static function resume(): void
    {
        if (!self::hasKey()) {
            throw new StorageException('Set a passphrase first.');
        }
        Settings::save(['encrypt_enabled' => true]);
    }

    private static function passphrase(): string
    {
        $enc = (string) Settings::get('encrypt_passphrase_enc');
        if ($enc === '') {
            throw new StorageException('Encryption is not set up.');
        }
        return Security::decrypt($enc);
    }

    public static function generatePassphrase(): string
    {
        return bin2hex(random_bytes(18));
    }

    public static function format(string $secret): string
    {
        return trim(chunk_split($secret, 4, '-'), '-');
    }

    public static function isEncryptedFile(string $abs): bool
    {
        return is_file($abs) && FileCrypto::hasMagic($abs, self::MAGIC);
    }

    /** The real (plaintext) size of $abs, whether or not it's encrypted — cheap, no passphrase needed. */
    public static function contentSize(string $abs): int
    {
        if (self::isEncryptedFile($abs)) {
            $size = FileCrypto::plaintextSize($abs, self::MAGIC);
            if ($size !== null) {
                return $size;
            }
        }
        return (int) filesize($abs);
    }

    // ---------- Writing ----------

    /**
     * The last step of saving a file: $plainPath (a complete, already-assembled plaintext file)
     * becomes the real file at $destAbs — encrypted first if enabled, otherwise moved as-is.
     */
    public static function finalizeWrite(string $plainPath, string $destAbs): void
    {
        if (!self::enabled()) {
            if (!@rename($plainPath, $destAbs)) {
                @unlink($plainPath);
                throw new StorageException('Could not save the file.');
            }
            return;
        }
        // Encrypted in the scratch folder, not next to the real file — so a crash mid-write can
        // never leave a stray temp file sitting visibly in someone's file listing.
        $tmp = self::scratchFile();
        try {
            FileCrypto::encryptFile($plainPath, $tmp, self::passphrase(), self::MAGIC);
        } finally {
            @unlink($plainPath);
        }
        if (!@rename($tmp, $destAbs)) {
            @unlink($tmp);
            throw new StorageException('Could not save the encrypted file.');
        }
    }

    // ---------- Reading ----------

    /**
     * A guaranteed-plaintext path for $abs's contents: $abs itself if it isn't encrypted, or a
     * decrypted temporary copy (cleaned up automatically at the end of the request) if it is.
     */
    public static function resolvePlaintext(string $abs): string
    {
        if (!self::isEncryptedFile($abs)) {
            return $abs;
        }
        $tmp = self::decryptToScratch($abs);
        register_shutdown_function(static function () use ($tmp): void {
            @unlink($tmp);
        });
        return $tmp;
    }

    /** Same as resolvePlaintext(), but the caller is responsible for deleting the temp file itself
     *  (for callers, like Zipper, whose library reads the file lazily rather than immediately). */
    public static function decryptToScratch(string $abs): string
    {
        $tmp = self::scratchFile();
        FileCrypto::decryptFile($abs, $tmp, self::passphrase(), self::MAGIC);
        return $tmp;
    }

    private static function scratchFile(): string
    {
        $dir = rtrim((string) Config::get('data_dir'), '/') . '/encrypt-tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/' . bin2hex(random_bytes(12)) . '.tmp';
    }

    public static function cleanupScratch(): void
    {
        $dir = rtrim((string) Config::get('data_dir'), '/') . '/encrypt-tmp';
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 3600) {
                @unlink($f);
            }
        }
    }

    // ---------- Bulk migration: existing files, in either direction ----------

    /** @return array{converted: int, skipped: int, errors: string[]} */
    public static function encryptExistingFiles(): array
    {
        return self::migrate(static fn (string $abs) => self::isEncryptedFile($abs), function (string $abs): void {
            $tmp = self::scratchFile();
            FileCrypto::encryptFile($abs, $tmp, self::passphrase(), self::MAGIC);
            if (!@rename($tmp, $abs)) {
                @unlink($tmp);
                throw new \RuntimeException('could not replace the file');
            }
        });
    }

    /** @return array{converted: int, skipped: int, errors: string[]} */
    public static function decryptExistingFiles(): array
    {
        return self::migrate(static fn (string $abs) => !self::isEncryptedFile($abs), function (string $abs): void {
            $tmp = self::scratchFile();
            FileCrypto::decryptFile($abs, $tmp, self::passphrase(), self::MAGIC);
            if (!@rename($tmp, $abs)) {
                @unlink($tmp);
                throw new \RuntimeException('could not replace the file');
            }
        });
    }

    /** @param callable(string): bool $skip @param callable(string): void $convert */
    private static function migrate(callable $skip, callable $convert): array
    {
        if (!self::hasKey()) {
            throw new StorageException('Set an encryption passphrase first.');
        }
        @set_time_limit(0);
        $converted = 0;
        $skipped = 0;
        $errors = [];
        foreach (Database::all('SELECT id FROM bla_users') as $u) {
            $root = rtrim((string) Config::get('data_dir'), '/') . '/users/' . $u['id'] . '/files';
            if (!is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $f) {
                if (!$f->isFile() || $f->isLink() || Storage::isReserved($f->getFilename())) {
                    continue;
                }
                $abs = $f->getPathname();
                try {
                    if ($skip($abs)) {
                        $skipped++;
                        continue;
                    }
                    $convert($abs);
                    $converted++;
                } catch (\Throwable $e) {
                    $errors[] = $abs . ': ' . $e->getMessage();
                }
            }
        }
        return ['converted' => $converted, 'skipped' => $skipped, 'errors' => $errors];
    }
}
