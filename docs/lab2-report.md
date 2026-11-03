# Lab 2: Integration and Testing Report

Test date: 23 October 2026.

## Project and database

Topic: T2 - Spa & Beauty Center Website, extending the Lab 1 PHP application.
The local XAMPP installation uses Apache, PHP 8.2.12, and MariaDB 10.4.32.
Node.js 24.13.1 and an isolated Chrome profile provide browser verification.

The `spa_beauty_center` database contains `users`, `categories`, and `services`.
Each has a primary key; `services.category_id` references `categories.id`.
The sample data contains four categories and six treatments. Account emails and
service slugs are unique. Passwords are hashed, not stored as plaintext.

## Integrated features

The homepage and detail pages read actual MySQL records through shared PDO
configuration. Local administrators manage services with PHP forms, prepared
statements, server-side validation, CSRF tokens, and explicit deletion
confirmation. Customers register, log in, view their dashboard, and log out.
Server-side checks enforce the administrator/customer roles, including requests
made directly to management URLs.

The HttpOnly, SameSite=Lax `spa_session` cookie identifies server-side session
data. Login rotates the ID, and logout destroys the session. Protected requests
read the current account and role from MySQL. The separate `spa_theme` cookie
contains only `dark` or `light`, lasts 30 days, and survives logout. Cookies use
Secure on HTTPS; the local test URL uses HTTP. A browser-session cookie does not
promise an idle timeout or reliable termination when a browser restores tabs.

## Test results

| Verification | Coverage | Result |
| --- | --- | --- |
| Database integration | Connection, database-backed catalog/details, malformed links, friendly database-outage errors | 128 checks passed |
| Service CRUD | Create/retrieve/update/delete through HTTP forms; public pages reflect changes; validation, escaping, CSRF, and delete confirmation | 183 checks passed |
| Authentication and sessions | Registration, unique emails, password hashes, failed/successful login, session persistence/rotation, logout, old-session rejection, theme surviving logout | 188 checks passed |
| Access restrictions | Anonymous/customer GET and POST denials, forged roles, administrator operations, immediate role revocation and deleted-account rejection | 310 checks passed |
| Theme-cookie unit tests | Persistence, 30-day expiration, malformed values, migration, priority, blocked storage, and allowed values | 8 tests passed |
| Real Chrome verification | Theme toggle, cookie attributes/expiry, reload, details navigation, initial PHP theme, migration and invalid-cookie fallback | Passed |

Total: **809 PHP checks and 8 JavaScript tests passed**, plus the Chrome workflow.
All 27 PHP files and the three application/browser JavaScript files passed syntax
checks. Authentication and CRUD are exercised through HTTP requests to the actual
PHP forms; the Chrome workflow tests appearance and cookie behavior, not native
browser submission of every form.

Write tests use disposable accounts and services, run sequentially, and verify
that original account and service rows remain unchanged. Before and after this
run, the database contained zero users, four categories, and six services. The
temporary accounts and services were removed; no default administrator was left
behind. The browser check refreshes the saved dark-mode screenshot and removes
its isolated temporary profile.

## Problems and resolutions

No application defects were found in the integrated run. Review identified a
possible timing race in the browser test: a same-URL navigation could inspect the
previous document. The verifier now waits for the new document to load before
asserting its state. The combined suite was rerun after that test improvement.
The sequential runner also avoids test-fixture conflicts between suites.

## Repeat the checks

Start Apache and MySQL in XAMPP. From the project directory, run:

```powershell
powershell -NoProfile -File '.\tests\run-lab2.ps1'
```

Include the installed Chrome browser checks with:

```powershell
powershell -NoProfile -File '.\tests\run-lab2.ps1' -Browser
```

Use `-PhpPath 'C:\path\to\php.exe'` if PHP is installed elsewhere. The runner
restores the caller's directory and exits nonzero if a verification step fails.
Do not run the database suites concurrently or edit records while they run.

Source: the project folders. Schema and sample data:
[database/spa_beauty_center.sql](../database/spa_beauty_center.sql).
Visual evidence: [screenshot index](screenshots/README.md).
Administrator setup: [project README](../README.md#task-4-registration-and-authentication).
