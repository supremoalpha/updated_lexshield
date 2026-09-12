# Deploy LEXSHIELD

This tree is meant to go live with a **dedicated database**, **login OTP**, and **SMTP**.

## 1. Files to copy

Copy the project onto the server. Do not copy `.env` from another machine. Copy `.env.example` to `.env` and fill it in.

### XAMPP (Windows)

1. Copy this whole folder to `C:\xampp\htdocs\lexshield`
2. Start Apache and MySQL in the XAMPP Control Panel
3. Create a MySQL database (or run `php scripts/setup_database.php`)
4. Copy `.env.example` to `C:\xampp\htdocs\lexshield\.env` and set `DB_*`, `APP_KEY`, and SMTP
5. Open either:
   - http://localhost/lexshield/
   - http://localhost/lexshield/index.php
   - http://localhost/lexshield/lexshield.php
   - http://localhost/lexshield/auth/login.php

All links (CSS, login, admin, messages) use the `/lexshield` folder automatically. You can also set `APP_BASE_PATH=/lexshield` and `APP_URL=http://localhost/lexshield` in `.env`.

### PHP built-in server

```bash
php -S 0.0.0.0:8000 router.php
```

On Apache, keep the project `.htaccess`. The document root can be the project folder or `htdocs/lexshield`; `.env`, `sql/`, `storage/`, `config/`, and `vendor/` are denied.

## 2. Create your own database

```bash
cp .env.example .env
# Set DB_NAME, DB_USER, DB_PASS, APP_KEY, MAIL_* in .env

php scripts/setup_database.php --admin-email=you@gmail.com --admin-password='YourStrongPassword'
```

That creates the database and a least-privilege MySQL user (not `root`), runs migrations, and seeds SMTP settings from `.env`.

`setup_database.php` uses `sudo mysql` when it can. On shared hosting, create the empty database in the control panel, put those credentials in `.env`, then run:

```bash
php scripts/migrate.php
```

## 3. Activate OTP and SMTP

In `.env`:

```
LOGIN_OTP_ENABLED=true
ADMIN_OTP_ENABLED=true
CLIENT_REGISTRATION_OTP_ENABLED=true

MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_ENCRYPTION=smtps
MAIL_USER=you@gmail.com
MAIL_PASS=your-gmail-app-password
MAIL_FROM=you@gmail.com
```

Gmail App Password: Google Account → Security → 2-Step Verification → App passwords.

Every login then emails a 4-digit code. Client registration emails a 6-digit code. Forgot-password emails a reset link.

Send a test:

```bash
php scripts/test_smtp.php you@gmail.com
```

Or open **Admin → System Settings → Send a test email**.

Until `MAIL_PASS` is set, login OTP cannot be delivered. Set SMTP first, then sign in.

## 4. Production checklist

- `APP_ENV=production`
- `APP_KEY` is a 64-character hex secret (not empty)
- Database user can access only this database
- OTP flags are `true`
- SMTP test email arrives
- Virus scanning: ClamAV `clamscan` installed and `storage/clamav_db/*.cvd` present
- `http://your-host/.env` returns 404
- HTTPS in front of PHP (so HSTS and Secure cookies apply)
- Change the default admin password after first login

## 5. Local Cloud Agent / this machine

This environment already has a dedicated database named `updated_lexshield` and OTP turned on. Put a Gmail App Password in `.env` (`MAIL_PASS`) and in **Admin → System Settings**, then run `php scripts/test_smtp.php`.

Uploads are scanned with ClamAV against `storage/clamav_db` (CVD virus definitions). On XAMPP, install ClamAV and set `CLAMSCAN_PATH` if `clamscan` is not on PATH.

Do not open `.cvd` files in Word, Notepad, or VS Code. They are binary databases.

To download or refresh them:

1. Admin → System Settings → **Download virus database**
2. From the project folder: `php scripts/update_cvd.php` (add `--main` only if you also want the large `main.cvd`)
3. Official files (save into `storage/clamav_db`, keep the names):
   - https://database.clamav.net/daily.cvd
   - https://database.clamav.net/bytecode.cvd
   - https://database.clamav.net/main.cvd
4. Linux with ClamAV: `freshclam --datadir=storage/clamav_db`
