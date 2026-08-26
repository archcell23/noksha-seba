# Deploying to Hostinger

This site is static HTML/CSS/JS plus a small PHP backend (`api/`) that
stores site content and bookings in `data/`. Git deploy handles the code;
these steps handle the parts that must never come from git.

## One-time setup after connecting the Git repo

1. **Upload `data/config.php` by hand** (via hPanel File Manager or FTP —
   it's intentionally not in this repo, see below). Use this template:

   ```php
   <?php
   return [
       'password_hash' => 'PASTE_HASH_HERE',
   ];
   ```

   Generate a hash for your password with:
   ```
   php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
   ```
   (Run that on any machine with PHP — it doesn't need to be the server.)

2. **Check `data/` is writable** by the PHP process. Hostinger's default
   file permissions are normally fine (directories 755, files 644, owned by
   your hosting account) — nothing extra to do unless saves start failing.

3. Confirm `data/.htaccess` deployed correctly by visiting
   `https://yourdomain/data/content.php` directly — it should show a blank
   page / 403, never raw JSON.

## Why `data/content.php`, `data/bookings.php`, and `data/config.php` aren't in git

Those three files hold **live state**: real bookings, real edited site
content, and the admin password. If they were committed, every future
`git push` — even one just fixing a typo in `index.html` — would silently
overwrite whatever real data had accumulated on the server since the last
push. `content.php` and `bookings.php` don't need manual setup: the PHP
code creates them automatically (starting empty) the first time anything
is saved or booked. Only `config.php` needs the one-time manual upload
above, since there's no automatic way to know your password otherwise.

## Migrating existing browser-only data

If the site already has admin-edited content sitting only in your own
browser's local storage from before this backend existed, log into
`/#/admin` → **ড্যাশবোর্ড** and click **"সব ডেটা সার্ভারে পাঠান"** once. That
pushes everything currently in that browser up to the server so every
visitor sees it, not just you.
