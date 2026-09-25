# BLA-Cloud roadmap

Built in stages, and each stage is tested before the next begins.

| Stage | What it adds | Status |
|---|---|---|
| **1. Foundation** | Setup wizard, secure sign-in with required 2FA for admins, recovery codes, file manager (chunked uploads, folders, rename, delete, download), activity log, system status | ✅ **Done (v0.1.0)** |
| **2. Everyday files** | Trash bin & restore, move/copy, file versions, image thumbnails (GD/Imagick), in-browser previews (images, PDF, text, video, audio), search, zip download of folders, automatic database upgrades, background housekeeping | ✅ **Done (v0.2.0)** |
| **3. People & sharing** | Admin user management (invite, quotas, disable, password reset), share with other users, **secure share links** (password, expiry, view/upload/drop/edit), email (SMTP) notifications | ✅ **Done (v0.3.0)** |
| **4. Sync everywhere** | WebDAV for files (Windows/macOS/Linux, phone apps), **CalDAV/CardDAV** for calendars & contacts (iPhone, Android via DAVx5, Thunderbird, Outlook), plus app passwords for devices | ✅ **Done (v0.4.0)** — hand-written, not `sabre/dav` (kept the app dependency-free; see below) |
| **5. Calendar & Contacts apps** | Built-in web apps for calendars (month/week/day/agenda, events, email reminders) and contacts (name, phone, email, address, photo, notes) | ✅ **Done (v0.5.0)** — no recurring events or calendar sharing yet; see below |
| **6. Backups** | Scheduled encrypted backups, verify ("restore drill"), one-click restore, cron with a page-visit fallback | ✅ **Done (v0.6.0)** — local destination only, no S3 yet; see below |
| **7. Safe in-app updates** | Check for and apply new versions from inside the app, with automatic rollback if something goes wrong | Planned — needs an actual release channel to check against first |
| **8. Encryption at rest** | Optional libsodium file encryption with clear recovery guidance | Planned |
| **9. Apps system** | Documented plugin structure, install/enable/disable from the admin panel | Planned |
| **Later** | Video calls (WebRTC with PHP signaling + optional TURN server), document editor, desktop & phone apps | Architecture reserved |

Before real-world use (after Stage 3–4): an independent security review.

**Stage 4 notes:** the WebDAV/CalDAV/CardDAV server is hand-written rather than `sabre/dav`, to keep
BLA-Cloud dependency-free (unzip and upload, no Composer/`vendor/` build step). It covers what real
clients (Apple Calendar/Contacts, Thunderbird, DAVx5, Windows/macOS file mounting) actually use day
to day. Known gaps versus a full implementation: no `sync-collection` REPORT (clients fall back to
`getctag`, which all of the above support), and `calendar-query`/`addressbook-query` don't filter by
time range or other criteria — they return the whole collection, which is fine at personal scale.
DAV access is per-account only for now (no shared folders/calendars over DAV yet).

**Stage 5 notes:** events and contacts are stored as plain iCalendar/vCard text (the same rows CalDAV/
CardDAV sync), with a few denormalised columns (start/end time, reminder time, contact display name)
kept in sync on every write so the calendar grid and contacts list don't have to re-parse everything
on each page load. Deliberately out of scope for now, to keep this stage focused: recurring events
(RRULE), calendar sharing with other people, and time-range filtering in CalDAV reports (a synced
client gets the whole calendar, same as Stage 4). All times are the server's own clock — there's no
per-user timezone setting yet.

**Stage 6 notes:** backups go to a local folder only — S3/remote destinations weren't built this
pass, to keep scope focused on getting local backup/verify/restore right first. The backup
passphrase is stored encrypted under the app's own key, so scheduled backups can run unattended;
that only matters for a backup file alone (off the server) being unreadable without it — if
someone has the live server they have the data anyway. Restoring in place assumes the same database
driver (SQLite-to-SQLite or MySQL-to-MySQL); moving to a brand-new server that isn't running yet is
a manual process — see docs/INSTALL.md.
