# ADR 0031 — Fetching from file servers: FTP and FTPS under the web's rules

- Status: accepted
- Date: 2026-11-05
- Refines: ADR 0013 (fetching feed URLs), ADR 0030 (logins for feed sources)

## Context
Many shops and suppliers drop their feed on an FTP server. The legacy app used PHP's `ftp_*`
functions: no address check, so an address could reach anything the server can reach, and the
`PASV` reply trusted, so an FTP server could point the data connection at an internal address.

## Decision
- **One fetcher per kind of source, one set of rules.** `SourceFetcher` picks by scheme: `HttpFetcher`
  for the web, `FileFetcher` for file servers. `FileFetcher` talks to a `FileServer`; `CurlFtpServer`
  is the first one.
- **The address** (`UrlGuard`): `ftp://` or `ftps://`, the path of one file, no query or fragment.
  Ports: 21, 990 for `ftps://`, or from 1024 up. Refused in a path: an encoded control character (a
  `%0d%0a` would be a second command to the server) and `;type=` (it would make curl transfer in text
  mode, which rewrites line endings, and the file must arrive byte for byte).
- **The connection** is pinned to a public address that was checked (ADR 0013, shared as
  `AddressPin`). Passive mode only, and the address in a `PASV` reply is ignored
  (`CURLOPT_FTP_SKIP_PASV_IP`): the data connection goes to the same address as the control connection.
- **A redirect never leaves the web.** A web address that redirects to `ftp://` is refused.
- **`ftps://` means TLS or nothing.** TLS is demanded before the login is sent, and the certificate is
  verified: explicit TLS (`AUTH TLS`) on port 21 or a high port, implicit on 990. A server without TLS
  gets no username and no password, and the failure says so. A self-signed certificate is refused, as
  on the web.
- **Plain `ftp://` with a password is allowed**, with a warning on the form that it travels
  unencrypted. This is the one exception to ADR 0030's "encrypted only": many shared hosts offer
  nothing else, and refusing would send those customers back to uploading by hand. The risk is theirs
  and is stated where they choose it. Without a login, the client logs in anonymously.
- **A header login is refused on a file server.** A stored login never moves between the web and FTP,
  between `ftps` and `ftp`, between servers or between ports: each is another origin.
- **Size and time stand in for an ETag.** `SIZE` and `MDTM` make the validator (`1234:1790000000`). The
  same validator means the file is not downloaded again; a server that does not say both is downloaded
  every time, and the content hash still recognises an unchanged file. A file over the plan's limit is
  refused before its first byte, and a server that hides or understates its size is still stopped at
  the cap.

## Consequences
- FTP servers behind a VPN or on an intranet stay out of reach, like web addresses there.
- Tests run the real curl client against a small FTP server of the suite's own (a process on
  `127.0.0.1`), so the guards that only matter on a real connection are proven: a lying `PASV`, no
  password without TLS, binary mode, a cut-off transfer, a refused login. They found that curl reports
  an unknown file time as `2^32 - 1` where a C `long` is 32 bits wide.
- A successful FTPS handshake is not exercised by the suite: the fixture has no TLS. The certificate
  options are the same as on the web.
