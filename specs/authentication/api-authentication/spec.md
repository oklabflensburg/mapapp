# Authentication API (JSON session login)

**Status:** As-built spec, reverse-engineered from the existing implementation.
**Code:** `backend/src/Controller/SecurityController.php`, `backend/config/packages/security.yaml`, `src/stores/auth.ts`, `backend/src/Security/JsonLogoutResponseListener.php`
**Scope:** The session-based JSON login used by the Vue app (`/api/login`, `/api/me`, `/api/logout`). The EasyAdmin UI under `/admin` has its own session firewall with a browser form login and is **out of scope** except where it constrains the role model.

## Problem / intent

The frontend authenticates editors against the Symfony backend over the same-origin `/api` endpoints using the browser session cookie. Anonymous visitors can use the map read-only; only users with the editor role may create or edit nodes.

## Identity & roles

- Users are `App\Entity\User` records, looked up by `email` (entity user provider).
- Roles: `ROLE_EDITOR` and `ROLE_ADMIN`; the hierarchy grants `ROLE_ADMIN` → `ROLE_EDITOR`.
- `ROLE_ADMIN` additionally reaches `/admin` (EasyAdmin moderated content).
- Frontend `canEdit` is true when the user's roles include `ROLE_EDITOR` or `ROLE_ADMIN`.

## Requirements

### R1 — Login
- `POST /api/login` with JSON body `{ "email": string, "password": string }` (field names: `email`, `password`).
- Handled by Symfony's `json_login` firewall; success authenticates the session (cookie-based, same-origin) and the controller returns `{ "user": { name, email, roles } }` (HTTP 200).
- Invalid credentials short-circuit with **HTTP 401** before the controller runs.
- If the request somehow reaches the controller unauthenticated, it returns HTTP 400 `{ "message": "Invalid login request." }`.

### R2 — Current user
- `GET /api/me` is public.
- Returns `{ "user": { name, email, roles } }` when a session is authenticated; otherwise `{ "user": null }` (with the anonymous firewall token). Never errors for anonymous callers.

### R3 — Logout
- `POST /api/logout` is intercepted by the logout listener on the main firewall, invalidates the session, and returns JSON (see `JsonLogoutResponseListener`), so the SPA can call it with `fetch` and ignore the body.
- The frontend clears its local user state regardless of the request outcome.

### R4 — Frontend session handling
- The session cookie is sent automatically with same-origin requests; the SPA never handles tokens.
- The auth store resolves `canEdit` from the loaded user's roles; login failures (any non-OK response or network failure) are reported as `false` and do not throw.

## Acceptance criteria

- **AC1 (R1):** Given valid editor credentials, `POST /api/login` returns 200 with `{user: {name, email, roles}}` and a session cookie; subsequent same-origin API calls are authenticated.
- **AC2 (R1):** Given wrong credentials, the response status is 401 and no session is established.
- **AC3 (R2):** Given no session cookie, `GET /api/me` returns 200 with `{user: null}`.
- **AC4 (R3):** Given an authenticated session, `POST /api/logout` invalidates it; a following `GET /api/me` returns `{user: null}`.
- **AC5 (R4):** Given a user whose roles are `["ROLE_ADMIN"]`, the frontend grants editing rights (role hierarchy → editor).

## Open questions / assumptions

- None open; the admin form login is intentionally a separate mechanism (CSRF-protected browser form) and not part of this API contract.

## Verification

- `backend/tests/Controller/AuthenticationTest.php` covers the login/me/logout flow; `AdminLoginFormTest.php` covers the admin form.
- Not executed in this session; evidence is from code reading.
