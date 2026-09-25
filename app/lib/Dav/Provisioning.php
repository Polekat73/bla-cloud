<?php
declare(strict_types=1);

namespace BlaCloud\Dav;

use BlaCloud\Database;

/** Gives every account a default calendar and address book to sync into, since there's no app to create one yet. */
final class Provisioning
{
    public static function seedDefaults(int $userId): void
    {
        if (!Database::one('SELECT id FROM bla_calendars WHERE user_id = ? AND uri = ?', [$userId, 'personal'])) {
            Database::run('INSERT INTO bla_calendars (user_id, uri, display_name, color, ctag, created_at) VALUES (?, ?, ?, ?, 1, ?)',
                [$userId, 'personal', 'Personal', '#c9a227', Database::now()]);
        }
        if (!Database::one('SELECT id FROM bla_addressbooks WHERE user_id = ? AND uri = ?', [$userId, 'contacts'])) {
            Database::run('INSERT INTO bla_addressbooks (user_id, uri, display_name, ctag, created_at) VALUES (?, ?, ?, 1, ?)',
                [$userId, 'contacts', 'Contacts', Database::now()]);
        }
    }
}
