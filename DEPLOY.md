# Deploying to Hostinger

This site is static HTML/CSS/JS plus a small PHP backend (`api/`) that
stores site content and bookings in a `private_data` folder — a **sibling
of `public_html`, not inside it**. That's deliberate: Hostinger's git
deploy syncs `public_html` to exactly match this repo on every push,
including deleting anything not tracked here. Any real data (bookings,
edited content, the admin password) living inside `public_html` — even
gitignored — would get wiped the next time anyone pushes a typo fix. Living
one level up means git never touches it, and it's also never directly
web-accessible at all, regardless of `.htaccess` correctness.

Expected layout on the server:

```
~/domains/yourdomain.com/
  private_data/        <- created automatically by the PHP code; not in git
    config.php          <- the one file you create by hand, see below
    content.php          <- auto-created on first admin save
    bookings.php          <- auto-created on first booking
  public_html/          <- this repo, deployed by git
    index.html
    api/
    ...
```

## One-time setup after connecting the Git repo

1. **Create `private_data/config.php`** one level above `public_html`
   (via hPanel File Manager or SSH/FTP — the folder may not exist yet;
   create it). Use this template:

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

2. Nothing else — `api/_lib.php` creates `private_data/` and the other two
   files automatically the first time anything is saved or booked, with
   permissions that keep them private.

## Migrating existing browser-only data

If the site already has admin-edited content sitting only in your own
browser's local storage from before this backend existed, log into
`/#/admin` → **ড্যাশবোর্ড** and click **"সব ডেটা সার্ভারে পাঠান"** once. That
pushes everything currently in that browser up to the server so every
visitor sees it, not just you.
