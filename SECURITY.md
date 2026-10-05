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

## Our response targets

We aim to meet the following service levels from first receipt of a valid report:

| Stage | Target |
|---|---|
| Acknowledge receipt | within **2 business days** |
| Initial severity assessment (CVSS) | within **5 business days** |
| Fix or mitigation — **Critical** (CVSS 9.0–10.0) | within **7 days** |
| Fix or mitigation — **High** (7.0–8.9) | within **30 days** |
| Fix or mitigation — **Medium** (4.0–6.9) | within **90 days** |
| Fix or mitigation — **Low** (0.1–3.9) | next scheduled release |

Severity is assessed using CVSS v3.1. Timelines may be adjusted by mutual
agreement for complex issues; we will keep you informed of progress.

## Coordinated disclosure

We follow coordinated disclosure. We ask that you give us a reasonable
opportunity to release a fix before any public disclosure (typically up to
**90 days**, or sooner once a fix is available). We are happy to credit
reporters who wish to be acknowledged.

## Supply-chain security

- PHP dependencies are constrained by `roave/security-advisories`, which blocks
  installing a package with a known advisory, and the CI pipeline additionally
  runs `composer audit` and `npm audit` and **fails the build** on advisories in
  shipped (production) dependencies at *moderate* severity or higher.
- Automated dependency-update PRs are raised by Dependabot.
- Release packages are signed with the app's Nextcloud App Store certificate,
  which every Nextcloud server verifies on install and update.

## Supported versions

Security fixes are provided for the **latest released version**.
