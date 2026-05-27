# Security Policy

## Reporting a vulnerability

Please **do not** open public GitLab issues or merge requests for security
problems. Instead, send a report to:

- **Email**: security@moselwal.de
- **PGP**: download the Moselwal security key from
  <https://moselwal.de/.well-known/openpgpkey> (RFC 9580) and encrypt
  attachments
- **Signal**: on request

We commit to:

| Stage              | SLA                                    |
| ------------------ | -------------------------------------- |
| First acknowledgement | within **72 hours** (business days) |
| Vulnerability triage  | within **7 days**                   |
| Fix plan for Critical | within **14 days**                  |
| Coordinated disclosure window default | **90 days** after first response |

If you don't hear back within the first acknowledgement window, please
escalate via support@moselwal.de.

## Coordinated disclosure

We follow the [CVD principles](https://en.wikipedia.org/wiki/Coordinated_vulnerability_disclosure):
findings stay embargoed until a patch is available, then we publish a
GitLab Security Advisory + (if applicable) a CVE via our CNA. The reporter
is credited unless they ask to remain anonymous.

Public Security Advisories live at
<https://gitlab.moselwal.io/groups/devops/-/security/advisories>.

## Scope

In scope:
- All repositories under `devops/**` and `development/moselwal/**` on
  gitlab.moselwal.io
- All container images under `registry.moselwal.io/devops/images/**` and
  `registry.moselwal.io/development/moselwal/**`
- Production sites operated by Moselwal Digitalagentur GmbH

Out of scope:
- Findings that require physical access to a Moselwal device
- Denial-of-service via volumetric attacks
- Social engineering, phishing, or attacks against Moselwal employees
- Third-party services we use (please report directly to the vendor)

## Vulnerability handling process

1. Report received → automatic acknowledgement
2. Maintainer assigned within 72h → triage
3. CVSS scored + severity confirmed → tracking issue created (confidential)
4. Fix developed in a private branch + tested
5. Embargo end approaches → coordinated release: tag + security advisory
6. CVE published, reporter credited

This file is the canonical source of truth for the Moselwal vulnerability
disclosure policy and is mirrored at every repository under our control.
For corrections to the policy itself, open an MR against
`devops/repo-templates/SECURITY.md`.
