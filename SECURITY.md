# Security Policy

DoCoNEXT takes the security of Finder and its users seriously. This document
explains how to report a vulnerability and what to expect in response.

## Reporting a vulnerability

**Please do not open public GitHub issues for security problems.**

Report vulnerabilities privately, by either:

- using GitHub's **private vulnerability reporting** — the *Report a
  vulnerability* button under this repository's *Security* tab. This is the
  channel we prefer: your report, the discussion and the fix stay in one place
  that only you and the maintainers can see, until there is a fix worth
  publishing. You can be credited when the advisory goes out, or stay anonymous.
- emailing **security@doconext.com**, if you would rather not go through GitHub.

Please include, where possible: affected version(s), the Nextcloud and PHP
versions in use, a description of the issue and its impact, and steps to
reproduce or a proof of concept.

## What to expect

Finder is maintained by a small team. We confirm receipt of a report within a
few business days, assess its severity (CVSS v3.1), and work on a fix in order
of severity — a critical issue goes before everything else. We keep you
informed of progress, and we would rather tell you honestly how long something
will take than promise a date we cannot keep.

## Coordinated disclosure

We follow coordinated disclosure. We ask that you give us a reasonable
opportunity to release a fix before any public disclosure (typically up to
**90 days**, or sooner once a fix is available). We are happy to credit
reporters who wish to be acknowledged.

## Known limitations

Worth knowing before you report them: these are properties of Nextcloud, or
of how the DoCoNEXT apps are designed, rather than defects in Finder.

- **Finder adds no access model of its own.** It searches with the rights the
  signed-in user already has in Nextcloud: whatever that user can read in the
  Files app, Finder can find. Nothing more, and nothing less.
- **File metadata travels with the file.** Nextcloud attaches metadata to a
  file, not to the folder it sits in. When DoCoNEXT Core has written record
  metadata on a file (a client number, a confidentiality class, …) and someone
  shares that file with a person outside the record, that person can read the
  metadata — in Finder's details panel, and via WebDAV. Publishing a copy
  through Core (to a collaboration space, say) does not carry it over; a share
  does. Treat shares of individual files out of a record accordingly.
- **External storage with one shared account.** A mount configured with fixed
  credentials, or made available to everyone, is searched by everyone on the
  mount with the full rights of that account — the source system's own
  permissions no longer apply. Mounts with per-user credentials are not indexed
  for content at all. Decide this when configuring the mount.
- **Group folders with very many access rules.** An account that is denied more
  than about a thousand paths by the Group folders app cannot search at all —
  Nextcloud's search backend rejects the access filter, in the Files app as much
  as in Finder. Finder reports this rather than returning nothing.

## Supply-chain security

- PHP dependencies are constrained by `roave/security-advisories`, which blocks
  installing a package with a known advisory, and the CI pipeline additionally
  runs `composer audit` and `npm audit` and **fails the build** on advisories in
  shipped (production) dependencies at *moderate* severity or higher.
- Automated dependency-update PRs are raised by Dependabot.
- A **Software Bill of Materials (SBOM, CycloneDX)** and a dependency license
  inventory are produced in CI and are available on request.
- Release packages are signed with the app's Nextcloud App Store certificate,
  which every Nextcloud server verifies on install and update.

## Supported versions

Security fixes are provided for the **latest released version**.
