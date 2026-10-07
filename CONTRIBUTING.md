# Contributing to Finder

Short version: **bug reports and feature requests are wanted and are read; code
contributions are not accepted.** The rest of this page explains why, so that
nobody discovers it after doing the work.

## What is welcome

### Bug reports

Open an issue. The most useful report says what you did, what happened, and what
you expected instead. Where you can, add:

- the Finder version, the Nextcloud version, and the PHP version;
- whether DoCoNEXT Core or the Files Preview app is installed — a good share of
  what Finder shows comes from those when they are there;
- whether the files involved sit on a Group folder (Team folder) or on external
  storage, which decides what Nextcloud lets a search see;
- anything relevant from `nextcloud.log`, with names and identifiers removed.

Please **do not paste code or a patch into an issue**. See below for why; an
issue containing a proposed implementation cannot be acted on and has to be
closed, which wastes your effort rather than ours.

### Feature requests

Describe the need rather than the solution: what you are trying to find, how
you look for it today, and what makes the current behaviour insufficient. A
request that carries its reasoning is one we can act on.

### Security vulnerabilities

**Never in a public issue.** Report privately through GitHub's *Report a
vulnerability* button under the Security tab, or by email to the address in
[SECURITY.md](SECURITY.md), which also says what to expect.

### Translations

Translations are a code contribution like any other, so the same rule applies.
If a string reads wrongly in your language, open an issue with the string and
what it should say — that we can act on.

## Why no code

Copyright in Finder is held undivided by DoCoNEXT, as it is in the other
DoCoNEXT apps. That is a deliberate choice, and it is load-bearing:

- **Provenance.** Finder is installed by organisations that must be able to say
  where their software came from and who is answerable for it. An undivided
  copyright means that answer has no footnotes.
- **Licensing.** It keeps our ability to license the work consistently over
  time, across all the DoCoNEXT apps.

Accepting outside code would end this permanently, and it cannot be undone one
contribution at a time. Opening a pull request is therefore limited to the
maintainers.

**This is a constraint on our development process, not on your rights.** Finder
is AGPL-3.0-or-later ([LICENSE](LICENSE)). You may fork it, modify it, and run
your modified version, subject only to the license.

## Building it yourself

If you are forking, or simply want to see how it is put together, the
[Development](README.md#development) section of the README covers local setup
and the quality gates. The complete corresponding source is this repository:
there is no separate build of Finder that we ship and do not publish.
