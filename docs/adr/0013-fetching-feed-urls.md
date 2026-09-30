# ADR 0013: Fetching feed URLs (SSRF policy)

- Status: accepted
- Date: 2026-10-01

## Context
A customer types a URL and FeedGenix's server fetches it. Without rules, that URL could make the
server talk to things only it can reach:
- the cloud metadata service (`169.254.169.254`, which hands out credentials);
- another application on the same host, such as the legacy Redis or MySQL on the shared beta VPS;
- private networks;
- admin ports.

Resolving a name once to check it and again to connect lets DNS rebinding slip through. So do
redirects that nobody re-checks. The legacy fetcher followed redirects blindly and turned TLS
verification off.

## Decision
Every fetch goes through `Domain/Ingest/Fetch/HttpFetcher`. The Arch tests allow raw curl, sockets
and DNS lookups nowhere else.

1. **The URL itself** (`UrlGuard`):
   - `http` or `https` only;
   - no credentials inside the URL;
   - a real host name or a standard IP address; purely numeric forms such as `2130706433` or
     `0x7f.1` are refused;
   - ports 80, 443, or 1024 and up, so mail, SSH and other admin ports are refused.
2. **Every address** the host resolves to must be public unicast (`AddressPolicy`). Refused:
   loopback, private, CGNAT, link-local and metadata, multicast, documentation and reserved ranges,
   and every IPv6 form that wraps an IPv4 address (mapped, compatible, NAT64, 6to4, Teredo).
3. **The connection is pinned** to the address that was checked (curl `CURLOPT_RESOLVE`). There is
   no second lookup, so there is no rebinding.
4. **Redirects are followed by hand**, up to 5, and each hop goes through steps 1–3 again.
5. **TLS is always verified.** The body streams to disk up to the size cap. A `Content-Length` that
   the body never reaches is a cut-off download.
6. **403 and 429 mean "try later"**, with the server's `Retry-After` (capped at a day), not "the
   feed is broken".
7. Downloads are conditional (`If-None-Match` / `If-Modified-Since`), so an unchanged feed costs a
   304 and nothing else.
8. `FEEDGENIX_FETCH_ALLOW_PRIVATE` lets development and end-to-end tests fetch from `127.0.0.1`. The
   app refuses to start the fetcher with it on in production.

On servers, the systemd sandbox (ADR 0011) blocks FeedGenix processes from TCP to the host's own
addresses too: a second, independent layer.

## Consequences
- Feeds on an intranet or behind a VPN cannot be fetched. Those customers upload the file, or wait
  for SFTP in M8.
- The transport (`CurlTransport`) is thin. The policy is tested against a scripted network, never
  the real one: 72 tests and 12 mutants, each caught. The transport itself is exercised end to end
  by the Playwright suite against a local server.
