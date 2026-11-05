# ADR 0030 — Logins for feed sources: encrypted, never returned, never sent elsewhere

- Status: accepted
- Date: 2026-11-05
- Refines: ADR 0013 (fetching feed URLs)

## Context
Many shops publish their feed behind a login. ADR 0013 refused credentials inside a URL and told
those customers to wait for M8. The legacy app stored the username and password in plain columns,
sent them over plain `http`, repeated them in the queue payload, and wiped them whenever a URL
import was saved without them (D16).

A stored login is someone else's secret, and it invites two attacks. Whoever may edit a feed could
point it at their own server and be sent the password. A shop's server, or anyone who takes it over,
could redirect the fetch and collect it.

## Decision
- **Two kinds:** a username and password (HTTP Basic, in UTF-8), or one request header
  (`X-Api-Key: …`, `Authorization: Bearer …`). `Domain/Ingest/Fetch/Credentials` holds either.
- **At rest:** `feed_sources.credentials`, encrypted with the app key. The kind sits beside it in
  `auth_type`, so listing feeds decrypts nothing; a database rule keeps the two together. Jobs carry
  ids only.
- **In the API:** `feed.auth` says the kind (`password`, `header` or null). The values are write-only:
  no response, page or log carries them, and the model hides them from serialisation.
- **What is not sent is kept (D16).** A `PATCH` without `auth` changes nothing; only `auth: null`
  removes the login. An empty object or an empty form is a validation error, not a removal.
- **A login belongs to one origin** (scheme, host and port):
  - Changing the URL to another origin drops the stored login, unless a new one comes with the
    request. `http` on the same host is another origin.
  - On a redirect to another origin the fetcher sends no login. A 401 there says so, rather than
    blaming the login.
- **https only.** A login on an `http` address is refused when saved, and again by the fetcher,
  whatever put it there.
- **Nothing can break out of a header.** Control characters are refused (`\z`, not `$`, so a final
  line break cannot slip through), a username has no colon, a header name is an HTTP token, and
  headers that shape the request itself (`Host`, `Transfer-Encoding`, `Accept-Encoding`, `If-*`,
  `Proxy-*`…) are refused.
- **Exactly as typed.** The login is exempt from Laravel's `TrimStrings`: a password that ends in a
  space still opens the door.
- **A changed login or URL forgets the ETag and Last-Modified**, which described what the old one was
  served.
- **The failure says which:** `login_needed` (no login, HTTP 401) and `login_refused` (the login was
  sent, HTTP 401). The feed page offers the source settings on both. A 403 stays "try later"
  (ADR 0013), and adds that the login may be the cause.

## Consequences
- Rotating the app key means re-saving logins, as with feed URLs. The kind stays readable, so a
  listing still works.
- A customer who moves a feed to another server must type the login again. That is the price of never
  sending it there by mistake.
- Sources that rotate a token on each request (OAuth) are not covered; they need a long-lived key or
  an upload.
