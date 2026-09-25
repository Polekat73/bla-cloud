# BLA-Cloud

**Your own private cloud, by [Best Life Apps](https://bestlifeapps.com).**
A safe place for your files that runs on your own server, written from scratch in plain PHP. There's no Docker to set up and it works on ordinary web hosting.

> **Status: Stage 3 of the roadmap (v0.3.0).** Setup wizard, secure sign-in with two-step verification, a full file manager,
> **people management and sharing** (with other people and by public link) are done.
> Phone/laptop sync, calendars, contacts and backups are coming next (see [docs/ROADMAP.md](docs/ROADMAP.md)).

---

## What you get today

- **3-minute setup wizard.** It checks your server, sets up the database, picks a safe storage folder and creates your admin account.
- **Secure sign-in**
  - Two-step verification with any authenticator app (Google/Microsoft Authenticator, Authy, 2FAS, Bitwarden, 1Password…)
  - **Required for administrators**, with 10 one-time recovery codes in case you lose your phone
  - Protection against password guessing: attempts are rate-limited per IP address and per account
  - Modern password hashing (Argon2id), and sessions reset whenever you sign in or change your password
- **File manager**
  - Upload by button or drag-and-drop, with a progress bar
  - **Big files work even on cheap hosting.** They're sent in pieces that fit your host's upload limit.
  - Folders, rename, **move and copy** (with a folder picker), and **download folders or selections as a zip**
  - **List or grid view** with **photo thumbnails** (your choice is remembered)
  - **Previews in the browser** for photos, PDFs, videos (you can skip ahead), music and text files, with ←/→ to flip through
  - **Search** every file and folder name
  - **Trash bin.** Deleted items wait 30 days and can be restored to where they were, even if the folder is gone.
  - **Version history.** Uploading a file with the same name keeps the old one. You can restore or download up to 10 older versions.
  - Handles international file names (é, ü, 中文, emoji…)
- **People**
  - Add family members or teammates. **Email them an invitation**, give them an invite link, or set a password yourself.
  - Storage limits per person, administrator role, disable or delete accounts, reset a lost phone (2FA)
  - **Forgot password?** Self-service reset by email (1-hour, single-use links)
  - Option to require two-step verification for everyone
- **Sharing**
  - **Share with people on your cloud**, who can view or edit. They find it under *Shared with me*, and you can get an email notification.
  - **Public links** with an optional **password** and **expiry date**, and these access levels:
    *view*, *view and upload*, *upload only* (a "file request" where visitors can't see what's there) or *edit*
  - Email a link straight from the share dialog, and see how many times a link was opened
  - *Shared by me* page to review and stop any share in one click
  - Shares follow files when they're renamed or moved, and stop when the file is deleted
- **Email** through any SMTP server (Gmail, Outlook, your host…) or the server's own mail, with branded messages
- **Automatic upgrades.** Upload a new version over the old one and the database updates itself on the next page load.
- **Housekeeping without cron.** Old trash, old versions and temp files are cleaned up automatically.
- **Security activity log.** You can see who signed in, from where, and what changed.
- **System status page** for admins: server health, disk space, database, and warnings.
- **Best Life Apps look and feel** throughout. Fonts are served from your own server, so nothing loads from Google.

## Requirements

| | Minimum |
|---|---|
| PHP | 8.2 or newer (8.3+ recommended) |
| PHP extensions | `pdo_sqlite` **or** `pdo_mysql`, `sodium`, `mbstring` (usually already on) |
| Database | Nothing extra with SQLite (default), or MySQL 5.7+/MariaDB 10.4+ |
| Web server | Apache (most shared hosting), Nginx, Caddy, LiteSpeed |
| HTTPS | Required when online (free Let's Encrypt in most hosting panels) |

## Install (the short version)

1. Download this repository as a zip and unzip it.
2. Upload the folder to your hosting (for example `public_html/cloud`).
3. Visit `https://your-domain.com/cloud/` and follow the wizard.
4. Sign in and connect your authenticator app. Done!

The full guide covers shared hosting, a VPS (Nginx/Caddy) and reverse proxies: **[docs/INSTALL.md](docs/INSTALL.md)**.

## Try it on your own computer

```bash
cd bla-cloud
php -S localhost:8080
# open http://localhost:8080
```

(The built-in PHP server is for testing only. It ignores the `.htaccess` protections.)

## Run the tests

```bash
php tests/run.php
```

These cover the two-step verification codes (official RFC 6238 test vectors), file path safety (blocking `../` escapes and symlinks), trash and restore, versions, move/copy, search, zip, thumbnails, reverse-proxy IP handling and the password rules.

## Upgrading

1. Back up `config/config.php` and your data folder.
2. Upload the new files over the old ones. Don't delete `config/config.php`.
3. Open BLA-Cloud in your browser. Any database changes happen automatically. Check **System status → Security activity** for the "upgrade" entries.

## Project layout

```
index.php            Single entry point
app/                 Program code (blocked from the web)
  lib/               Core: Auth, Totp, Storage, Security, Database…
  controllers/       Pages: setup, sign-in, files, settings, admin
  views/             HTML templates
assets/              CSS, JavaScript, fonts, logo
config/              Your config.php is created here by setup (blocked from the web)
data/                Fallback storage folder if you can't use one outside the website
docs/                Install guide, security notes, roadmap
tests/               Automated tests
```

## Security

See [docs/SECURITY.md](docs/SECURITY.md) for how BLA-Cloud protects your data, and what to do after installing.

---

© Best Life Apps. All rights reserved.
Bundled third-party pieces: Cinzel and EB Garamond fonts (SIL Open Font License, see `assets/fonts/`), and the QR code generator by Kazuhiko Arase (MIT License, see `assets/vendor/qrcode.js`).
