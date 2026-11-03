# Spa & Beauty Center Website

T2 - Spa & Beauty Center Website, extending the existing Lab 1 project.

## Project structure

```text
Spa & Beauty Center Website/
|-- auth/                    Account validation, sessions, and access checks
|-- components/              Reusable header and footer
|-- config/
|   `-- database.php         Shared database settings
|-- database/
|   `-- spa_beauty_center.sql  Schema and initial service records
|-- docs/
|   `-- screenshots/         Lab evidence
|-- includes/                Shared PDO connection, queries, and display helpers
|-- pages/                   Additional public pages, including service details
|-- public/
|   |-- index.php            Homepage
|   |-- css/                 Stylesheets
|   |-- js/                  Browser interactions
|   `-- images/              Logo and service photographs
|-- tests/                   Command-line integration verification
|-- tools/                   Local administrator setup and evidence tools
`-- README.md
```

The existing frontend is retained. Task 1 sets up the database. Task 2 loads the
catalog, category controls, featured treatment, and service details from MySQL.
Task 3 adds a local service manager for creating, editing, and deleting records.
Task 4 adds customer registration, login, logout, and a protected account dashboard.
Task 5 verifies authenticated sessions and remembers the display theme in a cookie.
Task 6 enforces administrator/customer permissions. Task 7 verifies the integrated
application and provides a single test command and a brief laboratory report.

## Database design

Database name: `spa_beauty_center`.

| Table | Purpose | Initial records |
| --- | --- | --- |
| `users` | Full name, unique email, password hash, and `admin`/`customer` role | 0 |
| `categories` | Service categories with unique names and slugs | 4 |
| `services` | Service name, slug, descriptions, price, duration, image, and featured flag | 6 |

Every table has an auto-increment primary key. The foreign key
`services.category_id` references `categories.id`: one category can contain many
services. A category cannot be deleted while services still reference it.

Prices are stored as whole VND amounts, without commas or currency text. Durations
are stored as numeric minimum and maximum minutes; manicure/pedicure uses 60-75,
and the other services use 60-60. Image filenames match `public/images/`.
Hot Stone Therapy is the initial featured service.

The SQL seed intentionally leaves the user table empty. Customers register on the
website; administrators are created with the local setup command below. Passwords
are stored using PHP's `password_hash()` and checked with `password_verify()`;
the database has no plaintext password column.

## Local setup

1. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
2. Open <http://localhost/phpmyadmin/>.
3. Choose **Import**, select `database/spa_beauty_center.sql`, and click **Go**.
   The SQL file creates the database, tables, categories, and service records.
4. Select `spa_beauty_center` in the left sidebar and verify its three tables.

The SQL file contains no drop or truncate statements. Re-importing it does not
overwrite existing seeded services or create duplicate copies of them. It is an
initial setup file, not a migration for changing an already existing schema.

The shared settings in `config/database.php` default to `127.0.0.1:3306`, database
`spa_beauty_center`, user `root`, an empty local XAMPP password, and `utf8mb4`.
Update the settings or supply the `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and
`DB_PASSWORD` environment variables if your installation differs.

Apache access to `config/`, `auth/`, `database/`, `includes/`, `tests/`, and `tools/` is blocked
by their `.htaccess` files. PHP can still include shared files using filesystem
paths, and verification scripts can run from the command line.

Homepage: <http://localhost/Spa%20%26%20Beauty%20Center%20Website/public/>.

## Task 1 evidence

The database has been imported into local XAMPP and checked for the expected three
tables, four categories, six services, and the service/category foreign key. The
homepage still returns HTTP 200. Requests for the configuration, SQL, and
authentication helper directories return HTTP 403 as intended.

See [the evidence index](docs/screenshots/README.md) for the captured phpMyAdmin
screenshots and instructions for refreshing them before submission.

## Task 2: PHP/MySQL integration

- `includes/database.php` reads `config/database.php` and reuses one PDO connection
  per request. It uses UTF-8, exception-based errors, associative records, and
  native prepared statements.
- `includes/services.php` contains the shared catalog, category, and single-service
  queries, plus HTML escaping and price/duration formatting.
- `public/index.php` renders the catalog and category buttons using database
  records. The first service marked `is_featured = 1` appears in the featured
  section; if none are featured, the section is omitted.
- `pages/item_details.php?service=<slug>` looks up one service with a bound query
  parameter. Existing service links continue to work.
- Dynamic text is HTML-escaped. Existing search, category filters, saved favorites,
  theme switching, and the booking-button interaction are retained.
- An empty catalog displays a helpful message. Database errors are logged by PHP,
  while visitors receive a friendly message and HTTP 503 without SQL or connection
  details. Unknown services return HTTP 404; malformed links return HTTP 400.

The existing no-parameter details URL defaults to Swedish Massage. Unknown slugs
do not silently fall back to another service.

### Verify database changes on the website

In phpMyAdmin, open `spa_beauty_center > services > Browse`, edit a service price,
and refresh the homepage and its details page. Both reflect the new price without
editing PHP. Restore the price afterward to retain the supplied price table.

Run the read-only integration checks in PowerShell from the project folder:

```powershell
& 'D:\xamp\php\php.exe' 'tests\task2-integration.php'
```

To also demonstrate a live database change automatically:

```powershell
& 'D:\xamp\php\php.exe' 'tests\task2-integration.php' --check-live-update
```

The optional live check briefly increases the Swedish Massage price by 1,000 VND,
checks both website pages, and restores the original record including its update
timestamp. Guarded updates prevent overwriting a concurrent edit. Connection
failure checks use isolated PHP processes with a nonexistent database name; they
do not stop MySQL or change the application's shared configuration.

## Task 3: service CRUD

Open <http://localhost/Spa%20%26%20Beauty%20Center%20Website/public/manage_services.php>
or choose **Manage Services** in the navigation.

- **Create:** select **Add Service**, complete the form, and submit it.
- **Read:** the management list shows categories, prices, and durations; **View**
  opens the public details page.
- **Update:** select **Edit**, change the fields, and choose **Save Changes**.
- **Delete:** select **Delete**, check the confirmation box, and submit the
  confirmation form. Opening the confirmation page does not delete anything.
  Only the service record is removed; its photograph is retained.

The forms validate required text, field lengths, unique lowercase URL slugs,
existing categories, allowed image filenames, positive whole VND amounts, and
valid duration ranges on the server. Errors retain the submitted form values.
Photographs are selected from the existing image library; uploads are not needed
for this task. All writes use prepared statements and CSRF tokens. Successful
submissions redirect to the list with a confirmation message.

`includes/service-management.php` holds validation, write queries, CSRF/session,
and flash-message helpers. The public routes are `manage_services.php`,
`service_form.php`, and `delete_service.php`; shared form fields and layout live
in `components/`, with styles in `public/css/management.css`.

Management access now requires an authenticated administrator, in addition to
the local-computer restriction. Customers cannot open or submit management
pages by entering their URLs directly. The `spa_session` cookie identifies a
server-side session used for authentication, form tokens, and feedback.

Run the CRUD workflow check with Apache and MySQL running:

```powershell
& 'D:\xamp\php\php.exe' 'tests\task3-integration.php'
```

The check logs in as a disposable administrator, creates a uniquely named service through the forms, verifies
create/read/update/delete and rejected submissions, removes that test service,
and confirms every pre-existing service row is unchanged. It never deletes the
original six services. The authenticated run passed 183 checks.

## Task 4: registration and authentication

Public registration: <http://localhost/Spa%20%26%20Beauty%20Center%20Website/public/register.php>.
Login: <http://localhost/Spa%20%26%20Beauty%20Center%20Website/public/login.php>.
Protected account page: <http://localhost/Spa%20%26%20Beauty%20Center%20Website/public/dashboard.php>.

Register with a full name, valid unique email, password, and matching password
confirmation. Passwords require at least 8 characters and at most 72 UTF-8 bytes.
Passwords are not trimmed or repopulated in responses. Registration always
creates a customer; a submitted `role` field cannot create an administrator.
After registration, log in to reach **My Account**. Incorrect credentials produce
the same message whether the email exists or not.

### Create your administrator

From the project directory, run this in PowerShell:

```powershell
& '.\tools\create-admin.ps1'
```

Enter your administrator name and a previously unused email. The command prompts
for a password and confirmation with hidden input. Log in on the normal login
page with those credentials, then choose **Manage Services**. No default account
or password is bundled with the application. This setup tool runs only locally
from the command line; its PHP backend is not accessible through Apache.

### Session and logout behavior

`auth/session.php` centralizes the HttpOnly, SameSite=Lax session cookie and CSRF
helpers. `auth/auth.php` contains prepared account queries, registration validation,
password hashing, fresh account lookup, and protected-page checks. Successful
login regenerates the session ID and stores only the user ID in session data.
Authorization reads the current database role rather than trusting a posted role
or a stale copy in the browser.

Choose **Log Out** from the dashboard or open `public/logout.php` to confirm.
Only a valid POST with a CSRF token ends the session. Logout clears server-side
session data and expires the session cookie. Protected pages disable caching;
subsequent requests, including replay of the old session ID, redirect to login.

Repeat authentication verification with:

```powershell
& 'D:\xamp\php\php.exe' 'tests\task4-integration.php'
```

The checks use disposable customer and administrator accounts and remove them
afterward, leaving existing accounts and services unchanged. They also verify
the session-cookie attributes and that the theme preference survives logout.

Four authentication screenshots are saved in `docs/screenshots/`. To refresh them
with the installed Node.js and Chrome, run `node tools/capture-task4-evidence.mjs`.
The capture tool registers a temporary customer through the real forms, logs in,
captures the protected page and logout view, then removes its temporary account,
session, and isolated browser profile.

PHP references: [password hashing](https://www.php.net/manual/en/function.password-hash.php),
[session ID regeneration](https://www.php.net/manual/en/function.session-regenerate-id.php),
and [session destruction](https://www.php.net/manual/en/function.session-destroy.php).

## Task 5: sessions and cookies

The existing PHP session keeps an authenticated account signed in while navigating
between protected pages. Anonymous requests redirect to login, customers cannot
manage services, and explicit logout destroys the session and invalidates its ID.

| Cookie | Purpose and contents | Expiration |
| --- | --- | --- |
| `spa_session` | An opaque identifier for server-side authentication, CSRF tokens, and feedback | Browser-session cookie (`lifetime = 0`); explicit logout destroys its server-side data and expires the cookie |
| `spa_theme` | Only `dark` or `light`, remembering the selected appearance | 30 days after the last selection or migration (`Max-Age=2592000` plus `Expires`) |

The theme preference is independent of login, persists after logout, and does not
contain a user ID, email, password, role, or authentication secret. Its path is `/`
so it applies to the homepage, details, and account/management pages. Both cookies
use `SameSite=Lax` and enable `Secure` on HTTPS. The session cookie is `HttpOnly`;
the cosmetic theme cookie is readable by JavaScript so the toggle can update it.

`public/js/script.js` reads and writes the preference cookie. It migrates a valid
old `spa-theme` local-storage setting once and removes that old key. Favorites
continue to use local storage. `includes/preferences.php` accepts only `dark` or
`light` and applies the theme in the initial HTML of all three shared page layouts.
The theme cookie never determines authentication or permissions.

When the theme cookie is missing, expired, or invalid and no legacy preference
remains, the page follows the device's light/dark setting. Selecting the toggle
starts a fresh 30-day preference lifetime. Normal navigation does not extend it.
Cookie/storage restrictions do not prevent the toggle from working on the open
page, although the browser may then be unable to remember the selection.

A browser-session cookie is not an enforced idle timeout. Explicit logout is the
supported way to end authentication; browser session restoration and PHP session
cleanup affect how long an otherwise unused session may remain available.

Verification commands (with Apache and MySQL running):

```powershell
node --test 'tests/task5-theme.test.cjs'
node 'tools/verify-task5-browser.mjs'
& 'D:\xamp\php\php.exe' 'tests\task4-integration.php'
```

For a manual demonstration, switch to dark mode, reload, and open a service or
account page. In browser developer tools, open **Application > Cookies > localhost**
and inspect `spa_theme`: its value is `dark`, its path is `/`, and its expiry is
approximately 30 days away. Log out to see the login session end while the theme
remains selected. See `docs/screenshots/README.md` for the saved visual evidence.

## Task 6: role-based access control

The application has two account roles: **Customer** and **Administrator**. Guests
can browse treatments but must log in to open the account dashboard.

| Capability | Guest | Customer | Administrator |
| --- | --- | --- | --- |
| Browse/search services and view details | Yes | Yes | Yes |
| View their own account dashboard and log out | Login required | Yes | Yes |
| List, add, edit, or delete services | Login required | No (HTTP 403) | Yes, on the server computer |

Public registration always creates a customer, even if someone submits a forged
`role=admin` field. Create an administrator with `tools/create-admin.ps1`, then use
the ordinary login page. There is no public role selector or bundled password.

The dashboard explains the signed-in role's permissions. Only local administrators
see **Manage Services** links. These links are not the security boundary:
`requireManagementAccess()` runs before handling any service-management request,
and `requireAdministrator()` checks the authenticated database account. Entering
an admin URL directly or sending a POST request as a customer returns a styled
**Access Denied** page with HTTP 403, without changing records. Anonymous local
requests redirect to login. The existing local-only management restriction also
remains in effect; remote requests are denied even for administrators.

The session stores the user ID, not a browser-supplied role. Each protected
request reads the account's current role from MySQL. Changing an administrator to
customer immediately removes management access on the next request; deleting an
account makes its existing session unauthenticated. Query parameters, form
fields, and cookies claiming an administrator role cannot grant access. Allowed
service mutations still require their existing CSRF and validation checks.

To demonstrate Task 6, log in as a customer, view **My Account**, then enter
`public/manage_services.php` in the address bar to see the denial. Log out and log
in as your administrator on this computer: **Manage Services** becomes available,
including the Add, Edit, and Delete controls. Use a temporary service when testing
writes so the original six treatments stay intact.

Run the role checks with Apache and MySQL running:

```powershell
& 'D:\xamp\php\php.exe' 'tests\task6-integration.php'
```

The verification passed 310 checks covering anonymous/customer GET and POST
denials, forged roles, administrator access and writes, immediate role revocation,
and deleted-account invalidation. It uses disposable accounts and a disposable
service, removes them afterward, and confirms every pre-existing account and
service row is unchanged. The Task 4 authentication regression also passed all
188 checks after these changes.

Optional browser evidence capture (Node.js and Chrome required):

```powershell
node 'tools/capture-task4-evidence.mjs' --task6
```

Four Task 6 screenshots are saved in `docs/screenshots/`: a customer dashboard,
a direct-URL access-denied page, an administrator dashboard, and the
administrator's service list. The capture used temporary accounts created through
the real registration and administrator setup flows. Both accounts were logged
out and removed, and the isolated browser profile was removed. No service records
were modified. See [the evidence index](docs/screenshots/README.md) for the images.

## Task 7: integration and testing

Start Apache and MySQL, then run the combined verification from this directory:

```powershell
powershell -NoProfile -File '.\tests\run-lab2.ps1'
```

To include real Chrome appearance/cookie checks, add `-Browser`:

```powershell
powershell -NoProfile -File '.\tests\run-lab2.ps1' -Browser
```

The runner executes the database, CRUD, authentication/session, authorization,
and theme-cookie suites sequentially. It reports each step and returns a nonzero
exit code on failure. Use `-PhpPath 'C:\path\to\php.exe'` to override the default
`D:\xamp\php\php.exe`. Node.js is required; the browser option uses the installed
Chrome. Do not run database suites concurrently or edit records during a run.

The complete run passed **809 PHP checks and 8 JavaScript tests**, plus the Chrome
workflow. It verified database connection, record creation/retrieval/update/delete,
registration, login/logout, session persistence, cookie behavior, and access
restrictions. Tests use disposable records and confirm every original account
and service is unchanged; they do not run the optional original-service price
change from Task 2. The browser verifier was tightened to wait for a new document
when navigating or reloading, avoiding assertions against the previous page.

See [the brief laboratory report](docs/lab2-report.md) for the database overview,
session/cookie explanation, test matrix, results, and testing improvements.
Screenshots demonstrating the integrated features remain in
[the evidence index](docs/screenshots/README.md).

## LaTeX laboratory report

The brief report required by the lab is available as a
[compiled PDF](docs/latex/lab2-report.pdf) and
[LaTeX source](docs/latex/lab2-report.tex). It covers the project topic, database,
implemented features, sessions/cookies, recorded test results, and problems and
resolutions, with selected screenshot evidence in an appendix. It includes a
university-style cover and clickable table of contents. Student details can be
edited in their command definitions near the top of the source.

LaTeX Workshop is installed in VS Code, and the workspace contains a Tectonic
build recipe. Open the `.tex` source and press **Ctrl+Alt+B** to compile it.
See [report editing and compilation instructions](docs/latex/README.md).
