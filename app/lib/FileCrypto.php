<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Shared low-level file encryption: libsodium secretstream (XChaCha20-Poly1305), chunked so file
 * size isn't limited by memory, keyed by a passphrase (Argon2id-derived, with a random salt per
 * file). Used by both Backup.php (whole archives) and Encryption.php (individual files at rest) —
 * each passes its own 8-byte magic prefix so the two are never mistaken for one another.
 */
final class FileCrypto
{
    public const CHUNK = 1024 * 1024; // 1 MB plaintext chunks while encrypting/decrypting

    public static function encryptFile(string $inPath, string $outPath, string $passphrase, string $magic): void
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = self::deriveKey($passphrase, $salt);
        [$stream, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $in = fopen($inPath, 'rb');
        $out = fopen($outPath, 'wb');
        if (!$in || !$out) {
            throw new StorageException('Could not open the file for writing.');
        }
        fwrite($out, $magic);
        fwrite($out, $salt);
        // The plaintext size, so callers (e.g. WebDAV's Content-Length) can learn it without a
        // full decrypt — no worse a disclosure than the ciphertext's own size, already visible on disk.
        fwrite($out, pack('J', (int) filesize($inPath)));
        fwrite($out, $header);
        while (true) {
            $chunk = fread($in, self::CHUNK);
            $eof = feof($in);
            $tag = $eof ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $enc = sodium_crypto_secretstream_xchacha20poly1305_push($stream, (string) $chunk, '', $tag);
            fwrite($out, pack('N', strlen($enc)));
            fwrite($out, $enc);
            if ($eof) {
                break;
            }
        }
        fclose($in);
        fclose($out);
        sodium_memzero($key);
    }

    public static function decryptFile(string $inPath, string $outPath, string $passphrase, string $magic): void
    {
        $in = fopen($inPath, 'rb');
        if (!$in) {
            throw new StorageException('That file is missing.');
        }
        if (fread($in, strlen($magic)) !== $magic) {
            fclose($in);
            throw new StorageException('That is not a file Haven encrypted.');
        }
        $salt = fread($in, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        fread($in, 8); // plaintext size — see plaintextSize()
        $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $key = self::deriveKey($passphrase, $salt);
        $stream = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $out = fopen($outPath, 'wb');
        if (!$out) {
            fclose($in);
            throw new StorageException('Could not write the decrypted file.');
        }
        while (!feof($in)) {
            $lenBin = fread($in, 4);
            if ($lenBin === false || strlen($lenBin) < 4) {
                break;
            }
            $len = unpack('N', $lenBin)[1];
            $enc = fread($in, $len);
            $res = $enc === false ? false : sodium_crypto_secretstream_xchacha20poly1305_pull($stream, $enc, '');
            if ($res === false) {
                fclose($in);
                fclose($out);
                @unlink($outPath);
                sodium_memzero($key);
                throw new StorageException('Wrong passphrase, or this file is corrupted.');
            }
            [$plain, $tag] = $res;
            fwrite($out, $plain);
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                break;
            }
        }
        fclose($in);
        fclose($out);
        sodium_memzero($key);
    }

    /** Does $path start with $magic? Used to tell an encrypted file from a plain one without a database lookup. */
    public static function hasMagic(string $path, string $magic): bool
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return false;
        }
        $head = fread($fh, strlen($magic));
        fclose($fh);
        return $head === $magic;
    }

    /** The original plaintext size, read from the header — no passphrase needed, no decrypting. */
    public static function plaintextSize(string $path, string $magic): ?int
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return null;
        }
        $ok = fread($fh, strlen($magic)) === $magic;
        $sizeBin = $ok ? fread($fh, SODIUM_CRYPTO_PWHASH_SALTBYTES + 8) : false;
        fclose($fh);
        if (!$ok || $sizeBin === false || strlen($sizeBin) < SODIUM_CRYPTO_PWHASH_SALTBYTES + 8) {
            return null;
        }
        $unpacked = unpack('J', substr($sizeBin, SODIUM_CRYPTO_PWHASH_SALTBYTES, 8));
        return $unpacked[1] ?? null;
    }

    private static function deriveKey(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $passphrase, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }
}
