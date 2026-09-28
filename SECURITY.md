# Security policy

## Supported versions

Eloquent RAG is in beta. Security fixes are made on the latest release
only; please upgrade to it before reporting.

| Version | Supported |
|---|---|
| latest `0.x` release | ✅ |
| older releases | ❌ |

## Reporting a vulnerability

**Please don't report security vulnerabilities in public GitHub issues,
discussions, or pull requests.**

Report them privately through GitHub's
[private vulnerability reporting](https://github.com/ahmed-nour-dev/eloquent-rag/security/advisories/new)
("Report a vulnerability" on the repository's **Security** tab). If that
isn't available to you, email the maintainer at the address listed in
[`composer.json`](composer.json) with "eloquent-rag security" in the
subject.

Please include:

- the affected version(s),
- a description of the issue and its impact,
- steps or a minimal proof of concept to reproduce it.

You can expect an acknowledgement within a week. Once a fix is ready, it
will be released and the advisory published, crediting you unless you'd
prefer otherwise.

## Scope

In scope: this package's own code — for example, SQL built by its search
and sync paths, handling of data it stores in `rag_*` tables, and its
Artisan commands.

Out of scope: vulnerabilities in Laravel, `laravel/ai`, your embedding
provider, or your database server themselves (please report those to their
maintainers), and issues that require an attacker to already control your
application's configuration or code.
