<div align="center">

<h1>Cloudflare SaaS Auto Onboarding Panel</h1>

<p>Custom hostnames · DNS records · TXT validation · Preferred entries · Origin management</p>

[![Docker installation checks](https://github.com/Sigusama/cloudflare-saas-panel/actions/workflows/ci.yml/badge.svg?branch=main&style=flat-square)](https://github.com/Sigusama/cloudflare-saas-panel/actions/workflows/ci.yml)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white)](docs/DEPLOYMENT.md)
[![MySQL 8.0+](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](docs/DEPLOYMENT.md)
[![License: AGPL-3.0-only](https://img.shields.io/badge/License-AGPL--3.0--only-2f6f54?style=flat-square)](LICENSE)

[简体中文](README.md) · **English**

[Screenshots](#preview) · [Features](#features) · [Quick start](#quick-start) · [Usage](#usage) · [Documentation](#docs) · [License](#license)

</div>

A PHP and MySQL panel for Cloudflare for SaaS. Manage custom hostnames, DNS records, TXT validation, entry hostnames, and origin settings in one place.

> [!IMPORTANT]
> **Current source: 0.1.0-beta.19 (development).** The installation example uses the published **beta.18** image. See [GitHub Releases](https://github.com/Sigusama/cloudflare-saas-panel/releases) for release packages and [build from source](#source-build) to run this development version. There is no stable release yet. Real Cloudflare onboarding requires your own account and test domains. `VERSION` is the source of truth; run `php bin/console.php version` to check an installation.

<a id="preview"></a>

## Screenshots

Manage records by zone. Preview edits, deletions, and mode changes before submitting them.

![English interface and DNS management using isolated example data](docs/images/multilingual-dns.jpg)

<details>
<summary>More screenshots: accounts, records, zones, and existing SaaS</summary>

#### Existing DNS records and mode changes

![Edit, delete, select, and switch existing DNS records; isolated test data](docs/images/original-dns-actions.jpg)

#### Account and zone selection

![Select all accounts or a specific account; isolated test data](docs/images/account-source-filter.jpg)

#### Create a DNS record

![Create a record with an origin IP and a preferred IP group; local test data](docs/images/entry-alias-record.jpg)

#### Zone and record overview

![Manage records by zone; local demo data](docs/images/clean-overview.jpg)

#### Discover existing SaaS

![Existing SaaS associations; simulated local data](docs/images/saas-inventory.jpg)

</details>

<a id="features"></a>

## Features

| Capability | What it does |
| --- | --- |
| **DNS management** | Load all DNS record types by zone; edit, delete, and process existing records in batches. |
| **Three routing modes** | DNS-only, proxied, and preferred. Preview differences before queuing changes. |
| **SaaS onboarding** | Create custom hostnames, configure routing and origins, handle TXT validation, and clean up temporary records created by the panel. |
| **Preferred entries and independent origins** | Use a preferred CNAME or 1–10 IPv4 / IPv6 addresses, with an origin IP or CNAME for each business record. |
| **Existing resource adoption** | Discover SaaS hostnames, certificate status, and DNS chains; confirm associations before adoption. |
| **Batch operations and migration** | Process up to 50 records per batch; import or export all configuration or a single zone as JSON. |
| **Tasks and change history** | Background execution, retries, diagnostics, snapshots, rollback, and audit logs. |
| **Deployment and integration** | Docker Compose, a Web installer, administrator sign-in, and a scoped public API. |
| **Chinese and English** | Language selection on installation, sign-in, and console pages; system labels and errors are translated while user data is preserved. |

<details>
<summary>Additional capabilities and operating limits</summary>

- Manage existing A, AAAA, CNAME, TXT, MX, CAA, SRV, and other Cloudflare record types. A / AAAA / CNAME support mode switching; DNS changes are applied only after confirmation.
- Share account and zone selection between the overview and records page. Group zones by account under “All accounts”; clear the old batch selection when switching zones.
- Business DNS, SaaS, and entry DNS may use different accounts. Ordinary DNS management remains available when a business zone has no SaaS fallback origin.
- Select records across pages within one zone. Validate versions, pending tasks, and ownership per item; failures do not block other items. Submissions support idempotency and batch auditing.
- Save reusable origin presets, check paths, expected HTTP statuses, and response keywords. Editing a preset does not automatically change existing domains.
- Protect referenced presets from deletion; support configuration disabling, task cancellation, failure recovery, explicit removal modes, and filtered or paginated audit logs.
- Distinguish desired configuration from applied snapshots. Record TXT creation, validation, cleanup, retries, and failures; clean up only temporary records owned by the panel.

</details>

Cloudflare is the only implemented DNS provider. DNSPod and other providers are future work. Entry targets and real origins are independent settings; the project does not supply a public preferred entry service.

<a id="quick-start"></a>

## Quick start

**Docker Compose is the recommended deployment method.** Web, MySQL, and the worker run separately with persistent data. The published image supports Linux amd64; use Docker Engine and Compose 2.20+.

### 1. Download the deployment files

The public image is `ghcr.io/sigusama/cloudflare-saas-panel:0.1.0-beta.18`. It can be pulled without signing in.

```sh
mkdir cloudflare-saas-panel && cd cloudflare-saas-panel
curl -fsSLO https://raw.githubusercontent.com/Sigusama/cloudflare-saas-panel/v0.1.0-beta.18/compose.yaml
curl -fsSL https://raw.githubusercontent.com/Sigusama/cloudflare-saas-panel/v0.1.0-beta.18/.env.example -o .env
```

### 2. Configure and start

Set two different strong database passwords in `.env`, then run:

```sh
docker compose pull
docker compose up -d --wait
docker compose logs web
```

### 3. Complete installation

Open `http://localhost:8080/install` and use the one-time installation token from the Web logs. The database host is `db`; the database name, user, and password match `.env`. The worker waits for setup and processes tasks after installation.

Web and worker use the same versioned image. Enter Cloudflare credentials in your own panel. Back up both MySQL and private storage before upgrades; retain the application key and installation lock. Update `PANEL_IMAGE`, pull, and recreate the containers. Ordinary `docker compose down` retains named volumes.

<a id="source-build"></a>

<details>
<summary>Build and run the current development source</summary>

```sh
git clone https://github.com/Sigusama/cloudflare-saas-panel.git
cd cloudflare-saas-panel
cp .env.example .env
```

Set both database passwords and change `PANEL_IMAGE` to `cf-saas-panel:local` in `.env`. Then run:

```sh
docker compose -f compose.yaml -f compose.build.yaml up -d --build --wait
docker compose logs web
```

</details>

For a PHP deployment, use PHP 8.3+, MySQL 8.0+, `public/` as the Web root, and a separately supervised PHP CLI worker. The application does not require Redis or a Node.js runtime. See [deployment instructions](docs/DEPLOYMENT.md) for the full configuration.

<a id="usage"></a>

## Usage

Choose **简体中文 / English** on the installation, sign-in, or console page. The browser remembers your selection; the first visit uses a supported browser language, falling back to Simplified Chinese. Domain names, account names, DNS content, and raw diagnostic data retain their original values.

### Bind a Cloudflare service

In **Accounts → Cloudflare accounts → SaaS service settings**, select the Cloudflare zone responsible for certificates and validation. The panel reads its fallback origin and standard CNAME candidates. A unique match is filled automatically; choose once when several candidates exist. Historical origin presets remain available in these settings.

Use an API Token with **Zone → Zone → Read**, **Zone → DNS → Edit**, and, for the SaaS account, **Zone → SSL and Certificates → Edit**. Restrict access to the required zones. Account email plus Global API Key is also supported. Credentials are encrypted and excluded from exports, public source, and images. See [deployment instructions](docs/DEPLOYMENT.md) for permissions and origin requirements.

### Create business records

Everyday work uses **Preferred targets** and **Domains**:

1. Select an authorized entry zone such as `aaa.com`, enter a prefix such as `cn`, and provide a preferred CNAME or an IP group. Enter one address per line, up to 10; mixed IPv4 / IPv6 is supported.
2. Save the entry as `cn.aaa.com`, optionally make it the default, then create a business record with its name, origin IP, and selected entry. You can choose a CNAME origin instead.
3. On first use, the worker configures entry DNS, completes SaaS / TXT validation, and applies the business CNAME. Saving configuration initially creates a local plan; the interface distinguishes pending and applied configuration.

```text
Business hostname dns.xxx.com
  CNAME → Custom entry cn.aaa.com
             CNAME → Your selected preferred hostname
             or A / AAAA → Your preferred IP group
  Via Cloudflare → This record's origin IP / CNAME
```

Multiple business records can share an entry; each business record still uses one CNAME. The IP group is published at the entry hostname. There is no automatic speed testing or failover.

<details>
<summary>Shared entries, independent origins, and compatibility</summary>

An entry's zone, prefix, and targets are protected once it is referenced or deployment has started. To change a target, create another entry and switch business records through the normal validation and rollback workflow. Removing a business record or local entry configuration retains shared entry DNS. Review other users before manually deleting it in Cloudflare. Upgrades do not rewrite legacy DNS automatically.

In preferred mode, origin IPs and CNAME origins receive an independent proxied alias in the SaaS zone for that business record. Changing one origin does not modify other records. DNS-only and proxied modes write the origin directly and skip SaaS onboarding.

New SaaS onboarding requires a standard SaaS CNAME. Existing working SaaS and preferred configurations can be adopted as they are, without creating another standard proxied CNAME or switching DNS. Preferred targets require real compatibility checks. Saved configuration alone does not establish working access; origin presets do not provide automatic load balancing.

For example, business DNS may be `aaa.aaa.com CNAME cn.aaa.com`, with the entry pointing to a preferred hostname or IP group and an independent alias pointing to that record's origin. Check the origin's virtual host, certificate, TLS, and SNI requirements in the [deployment instructions](docs/DEPLOYMENT.md).

</details>

<details>
<summary>Import/export, batch deletion, and rollback</summary>

Exports contain configuration and onboarding plans, excluding Cloudflare tokens, passwords, API keys, application keys, and live cloud resource IDs. Before importing on another server, add accounts, synchronize their zones, and bind the file's account references. A single-zone export includes only its dependencies and excludes global defaults. Full disaster recovery requires MySQL and private storage backups.

Default batch deletion removes the currently matching A / AAAA / CNAME onboarding records without restoring old DNS, and cleans up panel-owned SaaS hostnames and validation records. Unrelated TXT / MX, reused cloud hostnames, and historical resources are retained. The confirmation dialog also offers restoring original DNS or removing local management only. “Queued” means accepted for background work; check record details for the cloud result.

Each domain has its own applied snapshot. After editing an entry or origin preset, submit a configuration switch from record details to apply the new values. Failure recovery and manual rollback affect only that change. If external modifications occur during execution, the panel stops overwriting and records the conflict.

</details>

### Adopt existing SaaS

1. Add and synchronize accounts. The panel discovers enabled Cloudflare accounts automatically; you can restart discovery from **Import existing SaaS**. Business DNS, SaaS, and entry zones may belong to different accounts.
2. Review business hostnames, Cloudflare hostname and certificate status, CNAME chains, and real origins. Unambiguous CNAMEs and known origins are filled automatically. Third-party targets or ambiguous accounts require confirmation. DNS resolution does not establish target performance.
3. Select up to 20 items, confirm uncertain associations, and preview the adoption plan. Preview writes are rolled back locally. Confirmation rereads cloud resources to detect changes and preserves their external ownership.
4. Adoption retains existing DNS and origins and queues read-only inspection. Completion requires Cloudflare status, DNS and origin baselines, and actual HTTPS checks to pass. Existing hostnames, origins, and validation TXT are not treated as panel-created resources. Later explicit edits use the normal change workflow.

Discovery is paginated and can pause or resume. Permission errors, failed pages, loops, and unsupported records are reported. Scope follows account permissions; external DNS marks the discovery boundary. Wildcards, proxied business records, and unverifiable origins are currently read-only. Multi-address origins are retained as a complete set rather than choosing an arbitrary IP.

Configuration exports omit adoption baselines and live ownership. To take over existing resources from another installation, use discovery and adoption; use database and application-key backups for full disaster recovery.

<a id="docs"></a>

## Documentation

The detailed documents below are currently maintained in Simplified Chinese.

| Document | Contents |
| --- | --- |
| [Technical design](docs/TECHNICAL_DESIGN.md) | Source layout, service responsibilities, data, and task design |
| [Deployment](docs/DEPLOYMENT.md) | Docker and PHP installation, permissions, upgrades, and backups |
| [Public API](docs/API.md) | Authentication, scopes, endpoint contracts, and examples |
| [Verification status](docs/IMPLEMENTATION_STATUS.md) | Version-specific results, Docker / real Cloudflare checks, and future work |
| [Changelog](CHANGELOG.md) | Versions and changes |
| [Contributing](CONTRIBUTING.md) | Isolated tests, contribution requirements, packaging, and releases |
| [Security reporting](SECURITY.md) | Private reports and sensitive information handling |

## Testing and development

Run `node tests/i18n.js` for browser translation checks. See [Contributing](CONTRIBUTING.md) for PHP / MySQL and isolated HTTP tests. On a Linux host with Docker Engine, Compose, and curl, run `sh tests/docker-smoke.sh` to build the source and test installation, APIs, worker health, restart, and container replacement persistence. To test the released image instead, set `PANEL_SMOKE_IMAGE=ghcr.io/sigusama/cloudflare-saas-panel:0.1.0-beta.18`.

The current source passed 44 browser translation checks, 440 MySQL / simulated Cloudflare integration checks, and 182 / 180 fresh-install / restart HTTP checks locally. Version-specific Docker and real Cloudflare results are recorded in [verification status](docs/IMPLEMENTATION_STATUS.md); results for each commit are available in [GitHub Actions](https://github.com/Sigusama/cloudflare-saas-panel/actions/workflows/ci.yml). These checks do not establish compatibility with every account, origin, or architecture.

<a id="license"></a>

## License and attribution

This is an independent project and is not affiliated with or endorsed by Cloudflare. The repository does not include usable credentials, a public preferred entry service, or production configuration. Keep real credentials out of source, issues, and screenshots.

Copyright © 2026 [Sigusama](https://github.com/Sigusama) and contributors.

The source, documentation, and original visual assets use **GNU Affero General Public License v3.0 only (SPDX: AGPL-3.0-only)**. See [LICENSE](LICENSE) for the full terms and [NOTICE](NOTICE) for attribution. Preserve copyright and license notices and follow the corresponding-source and same-license requirements. Modified versions offered over a network must provide remote users with access to the corresponding source as required by the license.

Provide the complete corresponding source and build / installation instructions for the running version, with an easily discoverable source link. External runtimes, container images, and trademarks follow their respective terms. This software is provided without warranty; LICENSE governs the specific rights and obligations.
