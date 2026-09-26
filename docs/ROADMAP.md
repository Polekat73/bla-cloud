# Haven roadmap

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
| **8. Encryption at rest** | Optional libsodium file encryption with clear recovery guidance | ✅ **Done (v0.7.0)**, built ahead of Stage 7 (no release channel exists yet for safe updates) — file contents only, local files area only; see below |
| **9. Apps system** | Documented plugin structure, install/enable/disable from the admin panel | ✅ **Done (v0.8.0)** — in-process PHP apps, manual install (drop a folder in), no upload UI yet; see below |
| **10. Projects app** | Kanban-style project boards built on the Stage 9 apps system: tasks, columns, assignees, due dates, comments | ✅ **Done (v0.9.0)** — web UI only, no CalDAV/task-sync yet; see below |
| **11. AI integration (MCP)** | A Model Context Protocol server so an AI assistant can read and manage your own data | ✅ **Done (v0.10.0)** — full read/write, scoped to one person's own data, revocable tokens; see below |
| **12. Project chat** | Topic channels within a project, with invite/remove membership, so a connected AI can read a discussion and turn it into stages/tasks/documents | ✅ **Done (v0.11.0)** — polling-based (no WebSockets), text only; see below |
| **Later** | Video calls (WebRTC with PHP signaling + optional TURN server), document editor, desktop & phone apps | Architecture reserved |

Before real-world use (after Stage 3–4): an independent security review.

**Stage 4 notes:** the WebDAV/CalDAV/CardDAV server is hand-written rather than `sabre/dav`, to keep
Haven dependency-free (unzip and upload, no Composer/`vendor/` build step). It covers what real
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

**Stage 8 notes:** encrypts file *contents* only — file and folder names, sizes-as-metadata-in-the-
database (there isn't any; sizes are read from the file itself) stay as they are on disk, so this
protects against someone reading file contents, not against someone learning your folder structure
from filenames alone. Whether a file is encrypted is told apart by an 8-byte marker at the start of
the file, not a database flag, so it can never drift out of sync with what's actually on disk.
Turning it on doesn't retroactively encrypt existing files — that's the separate "Encrypt existing
files now" action, scoped to the main files area only (not trash or version history yet). There's
no seekable/chunked cipher: code that needs real bytes (HTTP Range requests for video/audio
scrubbing, thumbnails, zip downloads) decrypts a temporary full copy first, which is slower for
very large encrypted files than for plain ones — a deliberate simplicity-over-performance trade-off,
since a subtle bug in a custom seekable cipher risks actual data loss. There's no passphrase
rotation: changing it means turning encryption off (decrypting everything back to plain) and back on
with a new one.

**Stage 9 notes:** apps are in-process PHP (no sandboxing — a bad or malicious app can affect the
whole site, the same trust model as a WordPress plugin), each in its own folder under `app/apps/`
with a `manifest.php` declaring its routes and sidebar entry — see `app/apps/README.md`. Installing
one is manual file placement (unzip into `app/apps/`, then enable it in Administration → Apps), not
an in-browser upload — extracting and running arbitrary PHP from an admin upload was judged too
large a security surface for a first pass. The built-in Calendar and Contacts (Stage 5) were
converted to be the first two apps built on this system, proving it with real functionality rather
than a toy example; their underlying CalDAV/CardDAV sync (Stage 4) is unaffected by the app's
enabled state, since sync is core infrastructure, not part of the app. There's no per-app database
migration system yet — an app that needs its own tables creates them itself on first use.

**Stage 10 notes:** any project member can create/edit/move tasks, manage columns and comment; only
the owner can rename/delete the project or add/remove members — one role beyond "member" was judged
enough for v1. Tasks don't sync over CalDAV and due dates don't appear on the Calendar app yet
(a deliberate scope cut — see the Calendar/Contacts precedent in Stage 5 for why that integration is
harder than it looks). Dragging a card to a different column always appends it to the end of that
column; reordering within a column isn't tracked yet.

**Stage 11 notes:** `/mcp` is a Model Context Protocol server (JSON-RPC 2.0, "Streamable HTTP"
transport, single-JSON-response mode — no SSE stream, which keeps it simple to run on ordinary PHP
hosting). Authentication is a per-person, revocable "AI access token" (Settings → Sync → AI access),
the same shape as an app password but carried as a Bearer token instead of HTTP Basic. A token grants
**full read/write access to that one person's own data** — files, calendar, contacts, and projects —
and nothing else: never another user's data, never admin functions (no user management, settings,
backups or app enable/disable). That scoping is enforced by reusing the exact same authorization
checks the web UI uses (`ProjectsData::requireMember()`, `Storage`'s path containment, etc.), not a
separate copy of them. `files_read`/`files_write` are text-only and capped at 256 KB — binary files
and photos aren't readable or writable over MCP yet. There's no sandboxing of what a connected AI can
do with that access, so treat an AI access token with the same care as a password: only give it to an
assistant you trust, and revoke it if you stop using that integration.

**Stage 12 notes:** channel membership is deliberately separate from project membership — joining a
project doesn't put you in every channel, so a project can have a private topic without every member
seeing it. An existing channel member invites another project member in; only the channel's creator or
the project owner can remove someone (or delete the channel, or delete someone else's message —
moderation stays with the person accountable for the project, and doesn't require them to have
personally joined every channel they moderate). Messages update by polling every 3 seconds over
ordinary HTTP, not WebSockets, since typical shared hosting (including the ArzHost deployment this app
targets) can't run a persistent connection — see docs/INSTALL.md. No message editing, attachments, or
read receipts yet, and there's no automatic AI behavior built into the app itself: summarizing a
channel, turning it into tasks/stages, or drafting a scope-of-work document all happen through the
Stage 11 MCP integration — a connected AI calls `chat_get_messages` to read the discussion, then
`projects_create_column`/`projects_create_task` to turn it into work and `files_write` to produce a
Markdown document alongside the project's other files. That's a deliberate choice, not a limitation:
it means no new AI API key, no ongoing API cost, and no chat content leaving the server without a
person asking for it each time — see Stage 11 notes above for why.
