# BLA-Cloud roadmap

Built in stages, and each stage is tested before the next begins.

| Stage | What it adds | Status |
|---|---|---|
| **1. Foundation** | Setup wizard, secure sign-in with required 2FA for admins, recovery codes, file manager (chunked uploads, folders, rename, delete, download), activity log, system status | ✅ **Done (v0.1.0)** |
| **2. Everyday files** | Trash bin & restore, move/copy, file versions, image thumbnails (GD/Imagick), in-browser previews (images, PDF, text, video, audio), search, zip download of folders, automatic database upgrades, background housekeeping | ✅ **Done (v0.2.0)** |
| **3. People & sharing** | Admin user management (invite, quotas, disable, password reset), share with other users, **secure share links** (password, expiry, view/upload/drop/edit), email (SMTP) notifications | ✅ **Done (v0.3.0)** |
| **4. Sync everywhere** | WebDAV for files (Windows/macOS/Linux, phone apps), **CalDAV/CardDAV** for calendars & contacts (iPhone, Android via DAVx5, Thunderbird, Outlook) using `sabre/dav`, plus app passwords for devices | Next |
| **5. Calendar & Contacts apps** | Built-in web apps for calendars (events, reminders, shared calendars) and contacts | Planned |
| **6. Backups & updates** | Scheduled encrypted backups (local + S3-compatible), one-click restore with a tested restore drill, safe in-app updates with automatic rollback, cron with a page-visit fallback | Planned |
| **7. Encryption at rest** | Optional libsodium file encryption with clear recovery guidance | Planned |
| **8. Apps system** | Documented plugin structure, install/enable/disable from the admin panel | Planned |
| **Later** | Video calls (WebRTC with PHP signaling + optional TURN server), document editor, desktop & phone apps | Architecture reserved |

Before real-world use (after Stage 3–4): an independent security review.
