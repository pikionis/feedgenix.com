# ADR 0015: Unfinished dpkg packages and PHP modules on a host we share

- Status: accepted
- Date: 2026-10-02
- Decided by: the owner (keep the beta on the existing VPS); the mechanics follow from the survey
- Refines: [ADR 0011](0011-sandboxed-units-and-deploy-receiver.md)

## Context
The first read-only survey of the beta VPS (2026-10-02) found two things ADR 0011's provisioning
did not expect.

1. **A package dpkg left unfinished.** The `cloudpanel` package has been half-configured since
   2026-07-29. apt finishes unfinished packages on its next run, whatever that run installs, so any
   `apt-get install` re-runs that package's postinst. That 409-line script restarts services with
   `systemctl` and, on its install branch, drops CloudPanel's database and moves MySQL's data
   directory. `policy-rc.d` cannot block a script that calls `systemctl` itself. So ADR 0011's
   promise that "installing a package never restarts a legacy service" did not hold on this host.
   The upgrade/removal guard did not notice either: the simulation shows the re-run only as a
   `Conf cloudpanel` line.
   - What protects the host today is luck: CloudPanel is installed, so the script takes its update
     branch, and that branch fails at its first command, before restarting anything.
   - Hostinger's weekly `monarx-update` cron has re-run it that way since July.
2. **A control panel's PHP.** nginx and every PHP version on the host come from CloudPanel's
   repository. Its PHP 8.4 already loads `redis`, `intl` and the rest, but not from Ubuntu's package
   names: `php8.4-redis` does not exist there. A fixed package list made the install fail before it
   began, and on another host could install a second copy of an extension already built in.

## Decision
1. **apt never runs while dpkg has unfinished packages, unless each one is named.**
   - `provision.sh`, in both modes, lists the packages whose state is not finished: half-installed,
     unpacked, half-configured, trigger states, or reinstallation required.
   - It refuses before installing anything, naming the flag `--allow-unfinished=PKG[,PKG]`. The
     administrator gives that flag only after reading each package's maintainer script; the survey
     lists them with their dpkg history.
   - With the flag, apt's failure is accepted only if every package of the run is installed and no
     package other than the allowed ones is unfinished. Otherwise provisioning stops.
   - A converged host needs no apt run at all, so later runs never touch the unfinished package.
2. **PHP packages are chosen by missing module, not by name.**
   - For each module FeedGenix needs (`pdo_pgsql`, `redis`, `intl`, `mbstring`, the XML modules,
     `zip`, `curl`, `bcmath`), the package is installed only if the CLI or PHP-FPM does not load the
     module yet. The CLI itself is installed only if it is missing.
   - After the install every module must load in both, or provisioning stops. An installed but
     disabled extension surfaces here, not as a broken release.
3. The survey shows both up front: the modules still missing with a simulation of their packages
   (including `Conf` lines), and the unfinished packages.

## Consequences
- On the beta VPS provisioning runs with `--allow-unfinished=cloudpanel` and installs only
  `php8.4-pgsql` (plus libpq5), PostgreSQL 18 and pgvector. apt ends with cloudpanel's known error.
- **Repairing the `cloudpanel` package is not FeedGenix's to do.** Until someone does, every apt run
  on the host (Hostinger's weekly cron included) re-runs its script. The runbook (`ops/README.md`,
  "The beta VPS") says what to re-check before each provisioning run.
- Tests:
  - unit tests for the dpkg states, the allowance match and the module-to-package choice
    (8 of 8 mutants caught);
  - the CI rehearsal: next to a half-configured stand-in package, provisioning refuses in the dry
    run and the real run, runs the package's script only once it is allowed, tolerates only that
    failure, and runs no apt on the second pass.
