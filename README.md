# SiteConvoy

> [!WARNING]
> **Pre-release / Not Production-Ready**  
> SiteConvoy is currently under early foundational development. It contains **no executable migration engine or runtime plugin code yet**. Do not attempt to use this repository on production sites or live WordPress networks.

SiteConvoy is an open-source, full-spectrum WordPress migration plugin focused on WordPress Multisite networks and standalone installations.

---

## Purpose and Scope

WordPress Multisite migrations are notoriously complex due to shared database tables, blog-specific table prefixes, path routing (subdomains vs. subdirectories), serialized configuration data, user ID mappings, and media structures.

SiteConvoy is being designed to provide reliable, verifiable, and transparent migration workflows covering all four core directions without artificial paywalls or arbitrary file-size limitations:

1. **Multisite Subsite &rarr; Standalone WordPress**: Extract an individual subsite into a self-contained, independent single-site WordPress install. This includes first-class support and careful handling for the network's **main site (Site ID 1)**.
2. **Multisite Subsite &rarr; Another Multisite Network**: Transfer a subsite from one network into an existing target network, handling table prefix rewrites, user collision reviews, and network-specific media locations.
3. **Full Multisite Network &rarr; New Environment / Domain**: Package and migrate an entire network (all sites, network options, shared users, and uploads) across domains, hosts, or server environments.
4. **Standalone WordPress &rarr; Multisite Subsite**: Import an independent standalone WordPress site into an existing multisite network as a new subsite.

---

## Open-Source Principles

- **True Open Source**: Licensed under GPL-2.0-or-later, matching WordPress core.
- **No Artificial Gating**: No vendor lock-in, commercial feature paywalls, or arbitrary migration size limits.
- **Security by Design**: Explicit preflight checks, cryptographic integrity verification, cautious identity resolution (no silent account merging), backup checkpoints, and strict permission verification.
- **Test-Driven Reliability**: Full reproducible verification using real multisite fixtures, automated test suites, staged operations with best-effort compensating rollback, and transparent failure reporting.

---

## Current Status

- **Status**: Pre-release / Nonfunctional Foundation (Milestone M0).
- **Executable Code**: None. There is currently no executable PHP runtime code or user interface.
- **Prerequisites & System Requirements**: TBD (Targeting supported PHP and WordPress core versions; exact matrix to be determined during milestone M1).

For details on the project roadmap, architectural designs, and migration specifications, see:
- [Roadmap](docs/roadmap.md)
- [Architecture & Risk Analysis](docs/architecture.md)
- [Migration Matrix](docs/migration-matrix.md)

---

## Contributing

We welcome community collaboration, architectural feedback, and future code contributions! Please review our [Contributing Guidelines](CONTRIBUTING.md) and [Agent / Automation Guidelines](AGENTS.md) before submitting issues or pull requests.

Security disclosures should be reported according to our [Security Policy](SECURITY.md).

---

## License

SiteConvoy is free software licensed under the [GNU General Public License v2.0 or later (GPL-2.0-or-later)](LICENSE).
