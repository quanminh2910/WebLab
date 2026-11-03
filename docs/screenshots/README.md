# Lab 2 evidence

## Task 1: database setup

[Open all Task 1 screenshots on one page](task1-evidence.html).
With Apache running, use the
[one-click local evidence link](http://localhost/Spa%20%26%20Beauty%20Center%20Website/docs/screenshots/task1-evidence.html).

Captured Task 1 evidence:

- [Database structure](task1-database-structure.png): all three tables and row counts.
- [Service records](task1-services.png): all six services and their stored fields.
- [Category relationship](task1-category-relationship.png): the active foreign key.

Additional screenshots supplied on 26 October and included directly in the
report's **Database Overview** section:

- [Service records](database-services-evidence.png): stored service fields and VND prices.
- [Categories](database-categories-evidence.png): the four service categories.
- [Users table structure](database-users-structure-evidence.png): account columns, roles, and indexes.

To refresh these screenshots after later database changes, capture these views:

1. **Database Structure**: select `spa_beauty_center`; show the `users`, `categories`,
   and `services` tables.
2. **Services Browse**: open `services` and choose **Browse**; show the six services,
   VND prices, numeric durations, and image filenames.
3. **Category relationship**: use **Designer**, or open **services > Structure >
   Relation view**, to show `services.category_id` referencing `categories.id`.

Direct database URL:
<http://localhost/phpmyadmin/index.php?route=/database/structure&db=spa_beauty_center>.

The user table is empty until registration and administrator setup in Task 4.

## Task 2: PHP/MySQL integration

The report presents the saved evidence in confirmation order: the
[MySQL service record](database-services-evidence.png) first, then
[the corresponding website details](task2-service-details.png). The comparison
uses Swedish Massage: stored price `550000` appears as `550,000 VND`, and the
60/60-minute duration range appears as `60 min`. This is a comparison of saved
captures, not a newly captured database edit.

- [Database-driven catalog](task2-database-catalog.png): homepage with the featured
  treatment, category filters, and service cards rendered from MySQL.
- [Database-driven details](task2-service-details.png): Swedish Massage details,
  category, price, duration, description, and photograph read from the database.

The command-line integration checks also verified a temporary change to the
Swedish Massage price appeared on both pages and restored the original row
exactly. Invalid service links and unavailable-database responses were checked
for friendly errors without exposed SQL or connection details.

See the project README for commands to repeat the checks and live-update proof.

## Task 3: service CRUD

- [Service management list](task3-service-management.png): records with View,
  Edit, Delete, and Add Service controls.
- [Service form](task3-service-form.png): the shared add/edit fields populated
  with an existing service.
- [Deletion confirmation](task3-delete-confirmation.png): intentional checkbox
  and submit action; opening this view does not delete a record.

The Task 3 integration run passed 175 checks, including creating, displaying,
updating, and deleting a uniquely named temporary service through the PHP forms.
It also verified rejected invalid submissions, CSRF protection, escaped content,
and success messages. The temporary record was removed, and every original
service row remained unchanged. Task 2's 128 regression checks also passed.

Management now requires an administrator login and is limited to this computer.
The authenticated Task 3 regression run passed 183 checks. See the project README
for account setup and the verification command.

## Task 4: registration and authentication

- [Registration](task4-registration.png): required account fields and password confirmation.
- [Login](task4-login.png): the credential form.
- [Protected dashboard](task4-dashboard.png): a logged-in customer's account page.
- [Logout confirmation](task4-logout.png): a CSRF-protected logout form.

The Task 4 integration run passed 175 checks covering registration validation,
duplicate prevention, password hashing, login/session rotation, protected access,
administrator setup through the real CLI tool, customer access restrictions,
logout, and old-session replay rejection. A separate check verified logout still
destroys the session when the database is unavailable.

The verification accounts are temporary. Existing accounts and the six service
records remain unchanged. Use the project README instructions to register your
own customer or create an administrator with a password you choose.

## Task 5: sessions and preference cookies

New native Chrome evidence captured on 26 October 2026 and added to the report:

1. Saved preference: [website](task5-page-stored.png) and
   [native cookie inspector](task5-cookie-stored.png).
2. After reload and navigation: [service details](task5-page-persisted.png) and
   [unchanged cookie](task5-cookie-persisted.png).
3. After authenticated logout and a protected-page redirect:
   [login page](task5-page-after-logout.png) and
   [preserved preference cookie](task5-cookie-after-logout.png).

These are separate genuine page/DevTools views of each state, not a simulated
inspector. The report crops unused areas for readability; these originals remain
unchanged. The inspector is filtered to `spa_theme` so authentication session
identifiers are not disclosed.

[Safe cookie metadata](task5-cookie-metadata.json) records `spa_theme=dark`,
`Path=/`, `SameSite=Lax`, and the identical expiry `2026-11-25T14:31:10.877Z`
at all three stages. Remaining seconds decrease from 2,592,000 to 2,591,997 to
2,591,992. This verifies the configured 30-day lifetime and immediate persistence
across the actions; it does not mean 30 days elapsed during testing.

The collector submitted the normal registration/login/logout forms, verified
session rotation and a protected-dashboard redirect after logout, then removed
only its exact temporary customer account and isolated Chrome profile. A fresh
anonymous session on the login page is normal; logout ends authentication,
not the independent preference cookie. The captured theme cookie has HttpOnly
and Secure false because JavaScript updates this cosmetic preference and the
local site is HTTP. Secure is enabled on HTTPS.

With Apache and MySQL running, refresh all six screenshots using:

```powershell
node 'tools/capture-task5-evidence.mjs' --capture-authenticated
```

This requires local Chrome and PHP at the paths configured in the collector.
It never uses your normal Chrome profile or existing account. The JSON stores
only safe theme-cookie attributes, not credentials or session identifiers.

The earlier [saved dark appearance](task5-dark-mode.png) remains on disk for
the historical 23 October test run, but is no longer the report's sole cookie proof.

The browser check verified the cookie contains only `dark` or `light`, uses
`Path=/` and `SameSite=Lax`, and expires approximately 2,592,000 seconds (30 days)
after a selection. It checked toggling, reload, navigation to service details,
the initial PHP-rendered theme, malformed-cookie fallback, and migration from
the older local-storage preference.

All eight focused JavaScript tests passed. The authentication rerun passed 188
checks, including the session cookie's browser-session lifetime and attributes,
access to protected pages, login session rotation, logout invalidation, and
preservation of the dark theme after logout. Temporary verification accounts
were removed; all pre-existing accounts and services remain unchanged.

To demonstrate the cookie in browser developer tools, open **Application >
Cookies > localhost** and select `spa_theme`. Its appearance value is separate
from the HttpOnly `spa_session` identifier. Do not include session identifier
values in a shared screenshot. See the project README for commands and lifetimes.

## Task 6: role permissions

Captured Task 6 evidence:

- [Customer dashboard](task6-customer-dashboard.png): the customer role and its permissions.
- [Customer access denied](task6-customer-access-denied.png): HTTP 403 after opening the management URL directly.
- [Administrator dashboard](task6-admin-dashboard.png): administrator permissions and the management link.
- [Administrator services](task6-admin-services.png): the service list with administrator-only controls.

Capture completed successfully. Both temporary accounts were logged out and
removed, and the isolated browser profile was removed. No service records were
modified.

To refresh the evidence with Apache and MySQL running, use:

```powershell
node 'tools/capture-task4-evidence.mjs' --task6
```

The separate role integration test passed 310 checks, including direct GET/POST
denials and forged roles. The authentication regression passed all 188 checks.
All pre-existing accounts and services remained unchanged. See the project README
for the permission matrix, administrator setup, and test commands.

## Task 7: integrated verification

The integrated run passed all 809 PHP checks, all eight JavaScript tests, and the
real Chrome cookie workflow. The browser run refreshed
[the dark-mode screenshot](task5-dark-mode.png). The earlier Task 1-6 images above
demonstrate the database, dynamic pages, CRUD controls, account pages, and roles.

See [the laboratory report](../lab2-report.md) for the test matrix and results.
Repeat all checks with `powershell -NoProfile -File '.\tests\run-lab2.ps1' -Browser`
from the project directory after starting Apache and MySQL. Temporary verification
accounts and services are removed, and original records are checked for changes.
