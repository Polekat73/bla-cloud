<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Audit;
use BlaCloud\Auth;
use BlaCloud\Dav\ContactsBackend;
use BlaCloud\Dav\Vcard;
use BlaCloud\Database;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\StorageException;
use BlaCloud\View;

/** The built-in address book: same data CardDAV syncs, with a simple list/detail UI. */
final class ContactsController
{
    private const MAX_PHOTO_BYTES = 2 * 1024 * 1024;
    private const PHOTO_MAX_DIM = 480;

    private function book(int $userId): array
    {
        $book = Database::one('SELECT * FROM bla_addressbooks WHERE user_id = ? AND uri = ?', [$userId, 'contacts']);
        if (!$book) {
            throw new StorageException('Your address book is missing. Try reloading the page.');
        }
        return $book;
    }

    public function index(): void
    {
        $u = Auth::requireUser();
        $book = $this->book((int) $u['id']);
        $q = trim(Request::get('q'));
        $rows = $q !== ''
            ? Database::all('SELECT * FROM bla_contacts WHERE addressbook_id = ? AND fn LIKE ? ORDER BY fn', [$book['id'], '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'])
            : Database::all('SELECT * FROM bla_contacts WHERE addressbook_id = ? ORDER BY fn', [$book['id']]);
        $contacts = array_map(static fn ($r) => $r + Vcard::parseContact($r['data']), $rows);

        $openId = (int) Request::get('open');
        $open = $openId ? Database::one('SELECT * FROM bla_contacts WHERE id = ? AND addressbook_id = ?', [$openId, $book['id']]) : null;
        if ($open) {
            $open += Vcard::parseContact($open['data']);
        }

        View::render('contacts/index', [
            'title' => 'Contacts', 'nav' => 'contacts', 'contacts' => $contacts, 'q' => $q, 'open' => $open,
        ]);
    }

    public function save(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        try {
            $book = $this->book((int) $u['id']);
            $given = trim(Request::post('given'));
            $family = trim(Request::post('family'));
            if ($given === '' && $family === '') {
                throw new StorageException('Please enter at least a name.');
            }
            $id = (int) Request::post('id');
            $existing = $id ? Database::one('SELECT * FROM bla_contacts WHERE id = ? AND addressbook_id = ?', [$id, $book['id']]) : null;
            $uri = $existing['uri'] ?? (bin2hex(random_bytes(12)) . '.vcf');
            $uid = $existing['uid'] ?? null;

            $fields = [
                'uid' => $uid, 'given' => $given, 'family' => $family,
                'phones' => self::pairs((array) ($_POST['phone_type'] ?? []), (array) ($_POST['phone_value'] ?? [])),
                'emails' => self::pairs((array) ($_POST['email_type'] ?? []), (array) ($_POST['email_value'] ?? [])),
                'address' => [
                    'street' => trim(Request::post('street')), 'city' => trim(Request::post('city')),
                    'region' => trim(Request::post('region')), 'postal' => trim(Request::post('postal')),
                    'country' => trim(Request::post('country')),
                ],
                'note' => trim(Request::post('note')),
            ];

            $existingPhoto = $existing ? Vcard::parseContact($existing['data'])['photo'] : null;
            [$fields['photoBase64'], $fields['photoType']] = $this->handlePhotoUpload($existingPhoto, Request::post('remove_photo') === '1');

            $vcf = Vcard::buildContact($fields);
            ContactsBackend::writeObject((int) $book['id'], $uri, $vcf);
            Audit::log((int) $u['id'], $existing ? 'contact.updated' : 'contact.created', trim("$given $family"));
            Session::flash('success', $existing ? 'Contact updated.' : 'Contact added.');
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('contacts');
    }

    /** Returns [base64, type] for the photo to store, or [null, null] for none. */
    private function handlePhotoUpload(?string $existingDataUri, bool $remove): array
    {
        if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
            if ($_FILES['photo']['size'] > self::MAX_PHOTO_BYTES) {
                throw new StorageException('That photo is too large (2 MB max).');
            }
            $info = @getimagesize($_FILES['photo']['tmp_name']);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
                throw new StorageException('Please choose a JPEG, PNG, WebP or GIF image.');
            }
            $src = match ($info[2]) {
                IMAGETYPE_JPEG => imagecreatefromjpeg($_FILES['photo']['tmp_name']),
                IMAGETYPE_PNG  => imagecreatefrompng($_FILES['photo']['tmp_name']),
                IMAGETYPE_WEBP => imagecreatefromwebp($_FILES['photo']['tmp_name']),
                IMAGETYPE_GIF  => imagecreatefromgif($_FILES['photo']['tmp_name']),
            };
            if (!$src) {
                throw new StorageException('Could not read that image.');
            }
            [$w, $h] = [imagesx($src), imagesy($src)];
            $scale = min(1, self::PHOTO_MAX_DIM / max($w, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagejpeg($dst, null, 82);
            $jpeg = (string) ob_get_clean();
            imagedestroy($src);
            imagedestroy($dst);
            return [base64_encode($jpeg), 'JPEG'];
        }
        if ($remove || !$existingDataUri) {
            return [null, null];
        }
        // Keep the existing photo: pull the base64 back out of its data: URI.
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/s', $existingDataUri, $m)) {
            return [$m[2], strtoupper($m[1])];
        }
        return [null, null];
    }

    public function delete(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        try {
            $book = $this->book((int) $u['id']);
            $row = Database::one('SELECT uri FROM bla_contacts WHERE id = ? AND addressbook_id = ?', [(int) Request::post('id'), $book['id']]);
            if ($row) {
                ContactsBackend::deleteObjectByUri((int) $book['id'], $row['uri']);
                Audit::log((int) $u['id'], 'contact.deleted');
            }
            Session::flash('success', 'Contact deleted.');
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('contacts');
    }

    /** Zips two posted arrays (type[], value[]) into [['type'=>...,'value'=>...], ...], dropping blanks. */
    private static function pairs(array $types, array $values): array
    {
        $out = [];
        foreach ($values as $i => $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $out[] = ['type' => (string) ($types[$i] ?? 'other'), 'value' => $v];
            }
        }
        return $out;
    }
}
