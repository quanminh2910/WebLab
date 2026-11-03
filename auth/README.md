# Authentication helpers

This folder contains shared PHP authentication and session helpers introduced in
Lab 2, Task 4.

Browser-facing registration, login, logout, and dashboard pages live in `public/`. Helpers in this
folder will be loaded by PHP with `require_once`; direct HTTP access is blocked.

`session.php` starts the named `spa_session` session, sets cookie attributes, and
provides CSRF tokens and authentication flash messages. `auth.php` validates
registration data, performs prepared user queries, hashes and verifies account
credentials, rotates sessions on login, destroys them on logout, and enforces
authenticated and administrator-only access.

The `users` table supports unique emails, password hashes, and `admin`/`customer`
roles. Public registration always creates customers. Run
`tools/create-admin.ps1` from PowerShell to create an administrator with a password
you choose. The SQL seed includes no default accounts or plaintext passwords.

The service manager now requires an authenticated administrator as well as local
access. The customer dashboard requires any authenticated account. The separate
`spa_theme` cookie added in Task 5 remembers only dark/light appearance for 30
days. It is independent of authentication and remains after logout. The session
cookie has browser-session lifetime; explicit logout ends its server-side state.

Task 6 makes role permissions explicit on the dashboard and returns a shared
styled HTTP 403 page to signed-in non-administrators who request management
routes. `requireManagementAccess()` guards every management route before queries
or writes, combining the loopback-only restriction with `requireAdministrator()`.
`isLocalRequest()` is shared by the server guard, navigation, and dashboard so
their local-access policy stays consistent. Hiding a link does not grant or deny
server access.

Roles come from a fresh database lookup through the session's user ID. Submitted
roles, query-string IDs, and cosmetic cookies are never authorization inputs.
Revoking an administrator's role or deleting their account takes effect on their
next request. Repeat the dedicated tests with `php tests/task6-integration.php`;
they use only disposable fixtures and preserve every pre-existing account and
service.
