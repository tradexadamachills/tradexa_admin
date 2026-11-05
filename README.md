# Tradexa Laravel Control Center

Laravel 13 + Blade + Fortify. This is the staff application, separate from the React customer website.

Start: `php artisan serve --host=127.0.0.1 --port=8000`.

- `/preview`: redirects to the authenticated dashboard; sample data has been removed
- `/login`: staff login; email/password staff access
- `/reviews`: protected screening/review queue

No default user is seeded. Provision staff interactively with `php artisan tradexa:staff your-email@example.com --role=admin`.

Run `php artisan test` for authorization, login, review, media and provider checks. Supabase/Didit credentials are server-only. Set the web root to `public/`, never this directory.

See [full setup and deployment guide](../docs/WEB_ADMIN_SETUP.md).
