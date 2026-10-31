# Security Policy

SiteConvoy takes security and data integrity seriously. Because multisite migrations handle sensitive database tables, user records, and network configuration files, strict security practices are foundational to this project.

---

## Reporting a Vulnerability

If you discover a security vulnerability in SiteConvoy, please report it responsibly:

- **Do NOT file public GitHub issues** for suspected security vulnerabilities.
- Please use [GitHub Private Vulnerability Reporting](https://github.com/amitworx/siteconvoy/security/advisories/new) on the repository.
- Alternatively, report security findings to the repository maintainer through verified GitHub profile channels.

When reporting a vulnerability, please include:
- A clear description of the vulnerability and its potential impact.
- Step-by-step reproduction instructions or a minimal proof of concept.
- Affected components or configurations (e.g., specific migration mode or database state).

We will acknowledge receipt of your report, evaluate the issue, and coordinate an appropriate fix before public disclosure.

---

## Migration Data & Customer Privacy

- **Never share real database dumps, customer migration archives, or live production credentials** in public bug reports, pull requests, issue trackers, or public chat channels.
- When submitting test cases or reporting anomalies, sanitize all personally identifiable information (PII), domain names, passwords, salt keys, and proprietary data. Use synthetic test fixtures only.
