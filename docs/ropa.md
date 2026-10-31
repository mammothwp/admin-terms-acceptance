# Record of processing activities — Terms & Conditions acceptance

Art. 30 GDPR record for the processing performed by the `mammoth-acceptance-logs`
plugin. **[operator]** markers indicate entries only the organisation running
the site can complete.

## Roles

| Role | Who |
|---|---|
| Controller | **[operator]** The organisation operating the WordPress installation. Name, address, and contact details here |
| Data Protection Officer | **[operator]** Where one is designated. Public bodies: see Law 4624/2019 Art. 6 (ΦΕΚ Α' 137/2019) |
| Joint controllers | **[operator]** Normally none |
| Processors | The site's transactional email provider (receipt delivery); the hosting provider. **[operator]** Name them |

The plugin's author is neither controller nor processor: the software is
supplied, not operated, and no data reaches the author.

## Purposes

1. Demonstrating that a specific user accepted a specific version of the terms
   (proof of acceptance).
2. Audit logging and accountability.
3. Access control and platform security.

## Legal bases

| Purpose | Basis |
|---|---|
| Proof of acceptance | Art. 6(1)(b) — necessary for the contract; Art. 6(1)(f) — establishing and defending legal claims |
| Audit logging | Art. 6(1)(f) — accountability |
| Access control and security | Art. 6(1)(b) and Art. 6(1)(f) |

Consent under Art. 6(1)(a) is **not** relied on. See
[dpia-screening.md](dpia-screening.md) §1.

## Categories of data subject

Authenticated users of the installation in the configured roles: employees,
contractors, and — on provisioned installs — subscriber staff. No guests or
anonymous visitors.

## Categories of personal data

| Category | Fields |
|---|---|
| Account identifiers | User ID; username and email address as at the moment of acceptance |
| Event data | UTC timestamp; local time, timezone and UTC offset as displayed; action taken |
| Version identifiers | Version ID, version number, effective date, content hash |
| Technical/network | Full IP address and its source; User-Agent string |
| Context | Site/application identifier |

No special categories (Art. 9) and no criminal-offence data (Art. 10).
No device fingerprinting, MAC addresses or location data — see
[dpia-screening.md](dpia-screening.md) §4.

## Recipients

| Recipient | What they receive | Why |
|---|---|---|
| The data subject | A PDF receipt of the version they accepted | Transparency; their own copy |
| Email provider (processor) | The receipt email: name, email address, acceptance time, terms text | Delivery |
| Hosting provider (processor) | All stored data, at rest | Hosting |
| **[operator]** Others | | |

The plugin discloses nothing to the author or to any third party of its own
accord.

## Transfers to third countries

The plugin performs **no** international transfer itself: everything it writes
stays in the site's own database.

Transfers can nonetheless arise from the services around it — and this is the
entry most often got wrong, because the hosting location is assumed to settle
the question. It does not: a receipt email routed through a non-EEA
transactional email provider is a transfer, even when the site is hosted
entirely in Greece.

**[operator]** Complete for every processor involved in email delivery,
logging, monitoring and support:

| Processor | Service | Location of storage/processing | Onward transfers | Transfer mechanism |
|---|---|---|---|---|
| | Email delivery | | | SCCs / adequacy decision / n/a |
| | Hosting | | | |
| | Logging or monitoring | | | |
| | Support / helpdesk | | | |

## Retention

See [retention-policy.md](retention-policy.md). Summary: acceptance records
are retained while the account exists plus a documented dispute period
(default 5 years), after which the IP address and User-Agent are erased and
the accountability record is retained. Published versions of the terms are
never deleted.

## Technical and organisational measures

- Acceptance records are append-only. The code has no update or delete path
  and the admin UI exposes none.
- Each record carries an HMAC signature over its immutable core, and a second
  over the erasable context, so lawful anonymisation does not read as
  tampering. Records are chained to their predecessor.
- The signing key can be held in `wp-config.php`, outside the database.
- Access to the acceptance log is restricted to an explicit list of manager
  accounts; holding an administrator role is not sufficient.
- The management UI can be removed entirely on locked-down installs.
- Log exports and terms-snapshot downloads are themselves logged.
- The written text of every version is preserved in the database, so evidence
  does not depend on an optional PDF library or on files on disk.
- **[operator]** Encryption at rest, backup policy, access review cadence.
