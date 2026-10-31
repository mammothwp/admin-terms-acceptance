# Privacy

## What is stored

Per acceptance (table `{prefix}mmwp_terms_acceptances`): user ID, login and
e-mail snapshot, the version accepted and its hash, UTC and local time with
timezone, the site identifier, IP address and its source, User-Agent, and two
signatures plus a chain link. Events about a record (receipt delivery,
anonymisation) go to `{prefix}mmwp_terms_audit_events`. Each user also gets
three user-meta markers recording the version they accepted.

No device fingerprinting, MAC address or geolocation is collected.

## Legal basis

GDPR Art. 6(1)(b) and 6(1)(f). Not consent (Art. 6(1)(a)); acceptance is not
withdrawable and records are not deleted on request. See
`dpia-screening.md`, `ropa.md`, `retention-policy.md`.

## Retention

IP address, its source and the User-Agent (the erasable context) are cleared
after the configurable dispute period (default five years) by a daily sweep; the
core record keeps verifying. The anonymisation is audited.

## Personal-data tools

- **Exporter** (Tools → Export Personal Data) lists a user's acceptances.
- **Eraser** anonymises the erasable context where retention allows and
  reports what it retained and why; the core evidence stays.
- **Privacy policy text** is suggested via `wp_add_privacy_policy_content()`.

## Network

The plugin makes no outbound requests, loads no remote assets, and sends no
telemetry. Two kinds of e-mail leave through `wp_mail()`: the receipt to the
accepting user (and an optional address you configure), and a support request
to info@mammothwp.com that an administrator sends from Mammoth WP → Support.
The support request carries a site report only if the administrator ticks
"Include site report" after previewing exactly what it contains.

## Uninstall: what is kept and what is deleted

Always removed when the plugin is deleted: settings, options, scheduled tasks
and transients.

Kept by default (the acceptance evidence): the three tables
(`{prefix}mmwp_terms_acceptances`, `{prefix}mmwp_terms_versions`,
`{prefix}mmwp_terms_audit_events`), the per-user acceptance markers and the
signing key, so kept records can still be verified.

The choice is asked when you click Delete on the Plugins screen ("Keep
acceptance evidence (recommended)" or "Delete all acceptance evidence
permanently", the latter needing the plugin name typed back). It is stored in
the `mmwp_terms_uninstall_data` option and can be changed under Settings →
Terms & Conditions. The filter `mmwp_terms_drop_data_on_uninstall` overrides it.

## Purging evidence now

```bash
wp mmwp terms purge              # report what exists, delete nothing
wp mmwp terms purge --dry-run    # same, explicitly
wp mmwp terms purge --force      # permanently delete the evidence
```

Irreversible: after a purge no acceptance can be reproduced or proved.
