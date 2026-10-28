# ADR 0029 — The private beta answers at feedgenix.com; the other names redirect

- Status: accepted
- Date: 2026-10-28
- Refines: ADR 0009 (hosting, deploys and backups), ADR 0011 (sandboxed units and deploy receiver)

## Context
The beta has run at `staging.feedgenix.com` on the shared VPS. `feedgenix.com` and
`www.feedgenix.com` were parked at the registrar. The owner wants the site live at `feedgenix.com` now,
before the launch server exists (M11).

Three things were tied to one name:
- the nginx vhost and its certificate;
- `APP_URL`, `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` in `.env`, which `provision.sh` wrote once and
  never touched again;
- the feed URLs sales channels pull, which are built from `APP_URL`.

Sanctum's SPA sessions and CSRF need one origin. Serving the app on two names would split sessions and
need both names in the stateful list.

## Decision
- **One name per site.** `provision.sh --domain=feedgenix.com` makes it the site's own name.
  `--redirect-from=www.feedgenix.com,staging.feedgenix.com` lists the other names. Each one answers
  `301` to `https://feedgenix.com`, keeping the path and the query, on both 80 and 443.
- **The certificate.** One Let's Encrypt certificate covers every name. It is named after `--domain`
  (`--cert-name`). The old `staging.feedgenix.com` certificate stays and keeps renewing, so moving
  back needs no new certificate.
- **The domain keys of `.env` follow `--domain`.** Those keys are `APP_URL`, `FRONTEND_URL` and
  `SANCTUM_STATEFUL_DOMAINS`, and every run keeps them in step. The rest of `.env` is still never
  rewritten. A dry run shows only the changed lines, never the secrets next to them. After a change,
  the live release's cached configuration is rebuilt (`feedgenix-artisan optimize`), and PHP-FPM and
  the worker restart.
- **DNS first.** On a host that already serves FeedGenix, a run whose names do not all point here
  stops before changing anything. That covers each name's IPv4 addresses and any AAAA record, since
  Let's Encrypt prefers IPv6. The live site therefore never loses its certificate to a move whose
  DNS is not ready.
- **The checks.** `feedgenix-check` checks every redirect (a 301 to the site, path kept) and that
  `APP_URL` and `SANCTUM_STATEFUL_DOMAINS` name the site.
- **Search engines stay out.** The shared beta keeps `--indexing=block` until the legal pages and the
  price list are ready. Allowing indexing is the owner's call, made with `--indexing=allow`.
- **Registration stays invite-only.** `FEEDGENIX_REGISTRATION=invite` is unchanged.

## Consequences
- Feed URLs published from now on are `https://feedgenix.com/f/…`. At the launch the new server takes
  the same names through DNS and the beta's data moves with it (M11), so a channel never has to be
  told a new URL. Feed URLs already handed out on `staging.feedgenix.com` keep working through the
  redirect.
- Sessions on the old name end (the cookies are per host): people sign in again once.
- Moving back is the same command with the old flags.
- Google sign-in, once configured, needs `https://feedgenix.com/auth/google/callback` among its
  redirect URIs.
- The rehearsal (`ops/tests/rehearse.sh`) moves a provisioned host to a new name and back. It checks
  the redirects, the `.env` keys, a sign-in that holds its session on the new name, a deploy's smoke
  test, and that a second run changes nothing.
