# Deploying Student Financial Records online

Follow these steps in order. The whole thing usually takes 1 to 2 hours (domain changes can take longer to go live).

## 1. Get a domain and hosting
1. Buy a domain (for example `sfrecords.com`) from a registrar such as Namecheap, GoDaddy, or Hostinger.
   If you already own a personal domain, use a subdomain instead, for example `sfr.yourname.com`
   (create it in the hosting control panel under "Subdomains").
2. Choose PHP hosting with cPanel (shared hosting is enough). Requirements:
   PHP 8.1 or newer, MySQL 8 / MariaDB 10.4 or newer, and the `pdo_mysql` and `zip` PHP extensions.
3. Point the domain to the host: in the registrar, change the nameservers to the ones your host gave you,
   or add an A record to the host's IP address.

## 2. Create the database on the host
1. cPanel > MySQL Databases: create a database, a database user with a strong password, and add the user to the database with "All privileges".
   Hosts add a prefix, so the names look like `cpuser_sfrms` and `cpuser_sfrmsuser`. Write all three down.
2. cPanel > phpMyAdmin > select your new database > Import.
3. Before importing `database/schema.sql`, open it in a text editor and delete the first lines
   `CREATE DATABASE ...` and `USE sfrms;` (you already selected the database).
4. Import the file. Then create the admin account:
   - On your computer run: `php -r 'echo password_hash("YourStrongPassword", PASSWORD_DEFAULT);'`
   - In phpMyAdmin > SQL, run:
     `INSERT INTO users (full_name, username, email, password_hash, role) VALUES ('System Administrator','admin','you@example.com','PASTE_HASH_HERE','admin');`
5. Optional: import `database/sample_data.sql` (remove its `USE sfrms;` line first) to fill the dashboard for your demo.

## 3. Upload the files
1. cPanel > File Manager > open `public_html` (or the subdomain's folder).
2. Upload everything from the project folder (zip it, upload, then Extract). Do not upload `sfrms.zip` itself afterwards.
3. Make sure `assets/img/logo.png` is included.
4. Edit `config/db.php` and set `DB_HOST` (usually `localhost` on shared hosting, not `127.0.0.1`),
   `DB_NAME`, `DB_USER`, and `DB_PASS` to the values from step 2.
5. Set the time zone in `config/db.php` if your school is not on Philippine time.

## 4. Turn on SSL (HTTPS) FIRST
1. cPanel > SSL/TLS Status (or "Let's Encrypt SSL") > run AutoSSL for your domain. It is free.
2. Open `https://yourdomain.com` and confirm you see the padlock.
3. Only after that, make sure the `.htaccess` file from the project is in the same folder as `index.php`.
   It redirects all `http://` visits to `https://` and blocks direct access to `config/`, `includes/`, `database/`, and `storage/`.
   (If your file manager hides it, enable "Show hidden files".)

## 5. Schedule the daily backup
cPanel > Cron Jobs > add a job, "Once per day" at 2:00 AM, with this command (ask your host for the correct PHP path if it differs):

    /usr/local/bin/php /home/CPANELUSER/public_html/cron_backup.php

It saves a database backup and a file backup every day and removes backups older than 14 days.
Also download a backup from the Backup & Restore page now and then and keep a copy on your own computer.

## 6. Final security checklist
- [ ] `https://` works and `http://` redirects to it
- [ ] `https://yourdomain.com/config/db.php` shows "Forbidden" (not a blank page or code)
- [ ] `https://yourdomain.com/database/schema.sql` shows "Forbidden"
- [ ] The admin password is strong and not the one used in testing
- [ ] No PHP errors are shown on screen (they are hidden automatically on a live server)
- [ ] The database password in `config/db.php` is not the same as your hosting account password

## 7. Final test, everything online
1. Landing page loads, logo shows, "Sign in" works.
2. Login: wrong password, wrong CAPTCHA, and 5 failed tries causes a lockout. Correct login works.
3. Students: add, search, edit, delete (a student with no payments).
4. Transactions: record a payment, view the receipt, edit, void, filter by date/category/status/method.
5. Dashboard numbers and charts update after new payments.
6. Reports: apply filters, open Print preview, print or save as PDF.
7. Activity Log: every action above appears with user, activity, date, and time.
8. Backup: create a database and file backup, download one, restore the database from a backup.
9. Log out, then confirm protected pages redirect to the login page.
