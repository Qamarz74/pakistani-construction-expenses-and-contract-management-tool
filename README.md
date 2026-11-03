# Home Construction Expense Tracker

Core PHP 8.2+, MySQL 8+, PDO MySQL, BCMath, Fileinfo and Mbstring. Bootstrap 5 loads from jsDelivr; no build step.

1. Create an empty MySQL database and a dedicated database user with SELECT, INSERT, UPDATE and DELETE access.
2. Import `database/schema.sql`, then `database/seed.sql`, using phpMyAdmin or the MySQL client. Import once into an empty database.
3. Copy `config.example.php` to `config.php`; set the database DSN, username and password. Prefer an `upload_dir` outside the public web root, writable by PHP. Upload limit defaults to 5 MB; set PHP `upload_max_filesize` and `post_max_size` accordingly.
4. From the project directory run `php setup.php admin`, then enter a password of at least 12 characters. This creates the single admin; no default password or public installer exists. On hosting without a terminal, run this CLI command locally against that database, then remove local database credentials.
5. Upload the application including its `.htaccess` files; exclude `.git/`. Use HTTPS. Apache must honor `.htaccess`: direct access to `app/`, `pages/`, `storage/`, `database/`, `tasks/`, `config.php` and `setup.php` must be denied. For Nginx or another server, configure the same denials explicitly before use. Point the browser to `index.php` and log in with the username/password from step 4.

For local use: `php -S 127.0.0.1:8080 router.php`, then open http://127.0.0.1:8080. Always include `router.php` so private files cannot be served directly.

Select a project, add contacts, then use **Add expense**. Bricks default to rates per 1,000 pieces; Sariya uses Mann (40 kg). Configure other units/conversions under Settings; variable trolley/dumper capacities never convert automatically. Saved purchases retain their original units and rates.

Record advances once under Payments, then apply them from payment details. Thekas count as expense only when completed/approved work cost is recorded. Cancelling a purchase releases its allocations; the payments remain as advances. Contract cancellation preserves approved costs and payment history. Reports use purchase/work dates for expenses and payment dates for cash; period balances are explained on the report.

Progress and resume instructions: `tasks/todo.md` and `tasks/plan.md`.
