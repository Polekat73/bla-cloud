# Changelog

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
