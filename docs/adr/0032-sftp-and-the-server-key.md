# ADR 0032 — SFTP: nothing is sent until the server proves it holds the key

- Status: accepted
- Date: 2026-11-05
- Refines: ADR 0030 (logins for feed sources), ADR 0031 (fetching from file servers)

## Context
The legacy app logged in over SFTP without checking who answered, so anyone between the two servers
could collect the password. It also read the whole file into memory (`$sftp->get($path)`), which a
large feed would not survive.

SSH has no certificate authorities: a client either knows the server's key or takes it on trust the
first time. Few sellers can name the key of their host's server.

## Decision
- **The address** is `sftp://host[:port]/path/file`, under `UrlGuard`'s rules for a file server. A path
  starting `/~/` is relative to the login's home. Ports: 22, or from 1024 up.
- **Two kinds of login:** a username and password, or a username and an SSH private key (with its
  passphrase, if it has one). A key is read when it is saved, so an unreadable key or a wrong passphrase
  is refused there and then, not on some later night. Keys are encrypted and write-only like every
  login (ADR 0030). A private key only works on `sftp://`.
- **The key is checked before anything is sent.** `SftpServer` connects, asks the server to prove its
  key, compares it, and only then logs in. A server that shows another key, or cannot prove one, gets no
  username, no password and no key.
- **Trust on first use, with an option to pin up front.** `feed_sources.host_key` holds the SHA-256
  fingerprint, in the form `ssh-keygen -l` prints. A customer may give it when creating the feed. If they
  do not, the key shown on the first fetch is trusted and kept **once a file has actually arrived**, and
  any other is refused from then on. The risk is one fetch's worth, and the page shows the fingerprint,
  so a careful customer can compare it.
- **A changed key stops the fetch**, naming both fingerprints (`host_key_changed`). A server rebuild is
  a normal event, so the feed page offers the way through: the owner or an admin clears the fingerprint
  or pastes the new one. FeedGenix never accepts a new key by itself.
- **A trusted key belongs to one origin**, like a login: an address on another host or port forgets it,
  unless a new one comes with the request. What is not sent is kept (D16).
- **The connection** is a socket FeedGenix opens itself, to the address `AddressPin` checked, and hands
  to the SSH library: no second DNS lookup can happen.
- **A stream, not a string.** The file goes to disk chunk by chunk; at most the plan's limit plus one
  byte is asked of the server, and the chunk callback stops at the limit whatever arrives.
- **The library stays behind a seam** (`SftpConnector`, `SftpSession`). phpseclib's exceptions stop in
  `PhpseclibSftpSession`: their messages can name paths and server details, and are never shown.

## Consequences
- phpseclib 4 becomes a direct dependency (it already came with Socialite).
- A customer whose host rebuilds the server sees their feed stop until they confirm the new key. That
  is the point of the check, and the message says what to do.
- Tests:
  - the order of things (no login before the key check, nothing sent to another key) is proven against
    a scripted session, everywhere;
  - the real client is proven against a real OpenSSH server, on `127.0.0.1` on the CI runner
    (`tests/Support/fixtures/start-sftp.sh`). Those tests skip on a laptop without one.
