# Changelog

## 0.8.0 — Stage 9: Apps system
- **Apps system**: an in-process PHP plugin framework. Each app is a self-contained folder under
  `app/apps/` with its own controller, views and a `manifest.php` declaring its routes and sidebar
  entry — see `app/apps/README.md` for the format and a worked example.
- **Administration → Apps**: enable or disable any installed app. Disabling one removes its pages
  and sidebar link but touches nothing else — its data, if any, is untouched and comes back the
  moment it's re-enabled.
- The built-in **Calendar** and **Contacts** (Stage 5) are now built as apps on this same system —
  proof that it's not special-cased for them. Their CalDAV/CardDAV sync (Stage 4) is core
  infrastructure and keeps working regardless of whether the app is enabled.
- Installing a new app is manual file placement (unzip into `app/apps/`), not an in-browser upload —
  deliberately, since extracting and running arbitrary PHP from an admin upload is a much bigger
  security surface than a human deciding what goes on their own server. See docs/ROADMAP.md.

## 0.7.0 — Stage 8: Encryption at rest
- **Encryption at rest** for file contents (Administration > Encryption): a separate passphrase
  (not your account password, not the app's own encryption key) protects files from anyone who
  only gets the raw data folder — a stolen disk, a misconfigured off-site copy, a curious host.
  File and folder *names* aren't encrypted, only the bytes inside each file.
- Turning it on only affects files saved from then on; "Encrypt existing files now" walks and
  converts everything already there (main files area only, for now), with a safety backup taken
  first. The reverse ("Decrypt existing files") is also available.
- Fully transparent to the rest of the app: downloads, previews, thumbnails, zip downloads and
  WebDAV all decrypt on the fly. File sizes shown everywhere (file list, storage used, WebDAV)
  are the real content size, not the (slightly larger) size on disk.
- Deliberately simple by design, not a seekable cipher: an HTTP Range request (video/audio
  scrubbing) or a thumbnail on a large encrypted file decrypts a temporary full copy first rather
  than seeking into ciphertext directly — slower for very large files, but far less code to get
  wrong. Trash and version history aren't covered by the bulk migration yet. See docs/ROADMAP.md.

## 0.6.0 — Stage 6: Backups
- **Encrypted backups**: the database, config and everyone's files, zipped and encrypted
  (libsodium secretstream) under a separate passphrase you set — not your account password, not
  the app's own encryption key, so a backup is still restorable even if the server itself is lost.
- Scheduled daily or weekly, with a configurable number kept; runs as a side effect of the site
  being visited (like other housekeeping), or reliably via the new `tools/cron.php` on hosts that
  allow real cron.
- **Verify** ("restore drill"): decrypts and sanity-checks a backup — including opening a SQLite
  snapshot and counting accounts — without touching anything live.
- **Restore**: replaces the database and files with a backup's contents, in place, after typing a
  confirmation phrase. Takes its own safety backup of the current state first, so a restore can
  itself be undone.
- Download any backup file to keep an off-site copy.
- No S3/remote destinations and no self-updating yet — see docs/ROADMAP.md.

## 0.5.0 — Stage 5: Calendar & Contacts apps
- **Calendar**: month, week, day and agenda views. Create/edit/delete events with a title, time
  (or all-day), location and description, and an optional email reminder (5 min to 2 days before).
  Add more calendars alongside the default one. Same data as CalDAV — a change in the web app shows
  up in your phone's calendar and vice versa.
- **Contacts**: a searchable list and an add/edit form covering name, multiple phone numbers and
  emails, a postal address, a photo (auto-resized) and notes. Same data as CardDAV.
- Reminders are emailed by the existing housekeeping job (hourly, no cron needed) — requires email
  to be set up in Settings.
- No recurring events and no calendar sharing yet — see docs/ROADMAP.md.

## 0.4.0 — Stage 4: Sync everywhere
- **WebDAV**: mount your files as a network drive from Windows, macOS, Linux or a phone file app —
  browse, upload, download, rename, move, copy and delete, with folder locking so Explorer/Finder
  can save in place.
- **CalDAV/CardDAV**: every account gets a default calendar and address book, syncable from Apple
  Calendar/Contacts, Thunderbird, DAVx5, Outlook add-ins and other standard clients — `.well-known`
  auto-discovery, `MKCALENDAR` to add more calendars, per-collection `getctag` change detection.
- **App passwords** (Settings > Sync): one revocable password per device, since sync clients can't
  answer a two-step verification prompt. Rate-limited and logged like regular sign-ins.
- No built-in calendar/contacts *app* yet — that's Stage 5. The data and sync already work today
  through any CalDAV/CardDAV client.

## 0.3.0 — Stage 3: People & sharing
- **People** (admin): add people by email invitation, invite link or password. Set storage limits, the admin role, and disable, delete or reset 2FA. Quota bars and last sign-in.
- **Forgot password** self-service by email, plus admin-created reset links.
- **Settings** (admin): email (SMTP with STARTTLS/SSL, or mail()), public address, 2FA-for-everyone, trash/version retention, default quota, link policies (on/off, required password, maximum lifetime, suggested expiry), share notifications.
- **Share with people**, who can view or edit. Adds *Shared with me* and *Shared by me* pages and email notifications.
- **Public links** with password, expiry, label and access level (view, view and upload, upload-only file request, edit), an open counter, and emailing a link from the dialog.
- Shares follow renames and moves, and stop when an item is deleted.
- Branded HTML emails.

## 0.2.0 — Stage 2: Everyday files
- **Trash bin**: deleted items can be restored for 30 days, back to their original folder (re-created if needed). Delete forever or empty the trash.
- **Version history**: re-uploading a file keeps the previous one. You can restore, download or delete up to 10 versions per file. Versions follow renames, moves, and trash/restore.
- **Move & copy** with a folder picker. You can't move a folder into itself.
- **Zip download** of folders and multi-selections.
- **Thumbnails** for photos (GD, or Imagick when available). EXIF rotation is respected.
- **In-browser viewer** for images, PDFs, video/audio (seekable) and text, with keyboard navigation.
- **Search** across all file and folder names.
- **Grid view** with large thumbnails, remembered per browser.
- **Automatic database upgrades** on first page load after uploading new code.
- **Background housekeeping** (expired trash, old versions, stale temp files and thumbnails) without cron.
- Phone layout improvements for the file list.

## 0.1.0 — Stage 1: Foundation
- Setup wizard, sign-in with required two-step verification for admins, recovery codes, file manager with chunked uploads, activity log, system status page.
