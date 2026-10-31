# Producing acceptance evidence for a dispute

What to do when someone has to demonstrate that a named person accepted a
named version of the terms — and what to be careful about while doing it.

## The procedure

1. **Verify the log before you export it.** Users → Terms acceptance log →
   *Verify integrity*. Do this first: an export taken from a log you have not
   checked tells you nothing about whether the log is intact. Record the
   result, including a clean one.
2. **Filter to the person.** Filter by user ID, or open their profile and
   follow "View this user's acceptance records".
3. **Export the CSV.** It carries every field, both signatures, the chain
   link, and a per-row verification verdict.
4. **Attach the version.** From Settings → Terms & Conditions → Version
   history, download the PDF of the version they accepted. The record names a
   version; the version supplies the text. One without the other proves half
   the point.
5. **State the integrity position honestly** — see below.

## What the evidence actually shows

| Element | What it establishes |
|---|---|
| User ID + username/email snapshot | Which account acted, readable even after the account is deleted |
| UTC timestamp + local time and offset | When, unambiguously, regardless of later timezone changes |
| Version ID, number, effective date, hash | Which document |
| Archived version text | What that document said — the part a hash alone cannot supply |
| Action | That the act was an acceptance |
| Site identifier | Which installation it happened on |
| IP address + source | Where the request came from, and whether that came from the connection or a trusted proxy header |
| User-Agent | Corroborating circumstances only — trivially spoofable, never primary proof |
| record_hash / prev_record_hash | That the record has not been altered or unlinked since it was written |

## Integrity: state it accurately

The signatures are **tamper-evidence, not tamper-proofing**. Overstating this
is worse than claiming nothing, because the overstatement is what gets
attacked.

- **Key in `wp-config.php`** (`MMWP_TERMS_INTEGRITY_KEY`): the key sits outside
  the database. Someone with SQL access alone can alter a record but cannot
  produce a matching signature, so the alteration is detectable.
- **Key in the database** (the default when the constant is absent): detects
  accidental corruption and careless direct edits. Someone who holds the
  database also holds the key and could re-sign an altered record. Say so.
- Neither protects against an attacker with full filesystem access, who could
  alter the plugin itself.

Set the constant *before* you need to rely on the log. Records signed under
the old key stop verifying when the key changes.

## Known limitations to disclose

Disclose these rather than let them be discovered. A limitation you volunteered
costs you far less than one found in cross-examination.

- **Context signature on anonymised records.** Once a record passes its
  retention period, the IP address and User-Agent are erased and the context
  signature is cleared with them. This is the retention policy working as
  documented, not tampering; the record's own signature still verifies. The
  `anonymised_at_utc` field carries the date, and an `anonymised` audit event
  carries the reason.
- **Chain gaps.** If two acceptances are recorded at the same instant, the
  advisory lock that orders them can time out. The plugin records the gap as a
  `chain_gap` audit event and links nothing, rather than writing a link that
  looks valid and is not. The per-record signature is unaffected. A gap is a
  known, logged state — not evidence of interference.
- **Proxy addresses.** If the site sits behind a CDN or load balancer and
  `mmwp_terms_trusted_proxies` was never configured, `ip_address` is the
  proxy's address, and `ip_source` says `remote_addr`. Check that field before
  asserting anything about where a request came from.
- **User-Agent weight.** Corroborating only.
- **Receipt email.** The emailed PDF is supplementary. Delivery is logged
  (`email_sent` / `email_failed`), but delivery to a mail server is not proof
  the person read it. Where an ESP webhook is wired to
  `mmwp_terms_delivery_status_recorded`, provider-side status is available too.

## If verification fails

Do not "fix" anything. There is no repair function, deliberately: re-signing a
record that failed verification would destroy the only evidence that something
happened to it.

1. Note which record IDs failed and how (record signature, context signature,
   or chain link).
2. Preserve a database backup taken before any further change.
3. Check whether the integrity key changed — the most common innocent
   explanation, and one that invalidates every signature at once rather than a
   few.
4. Check the audit events around the affected IDs.
5. Treat a small number of failures against an otherwise valid chain as the
   more serious signal: wholesale failure usually means a key change, while
   isolated failure suggests a specific record was touched.

## Versions published programmatically

The `source` column on each version says how it was published: `settings` (an
admin typed it), `filter` (picked up from an external content source),
`rest`, `cli`, `api` or `code`. Quote it — "the version was published by the
legal team's CMS sync at 03:14 UTC" is a more complete account than "the
version existed".

Two things follow for programmatic publications:

- Where no version number was supplied, one was **derived** (`1.0` → `1.1`, or
  a dated identifier). A derived number is still unique and still names one
  specific document, but it was assigned by the system rather than chosen by a
  person — say so rather than implying a human numbered it.
- Where no effective date was supplied, none is recorded. The field is empty
  rather than inherited from earlier text, which is deliberate: an inherited
  date would be a false claim. An empty effective date is not a gap in the
  evidence — the publication time in `created_at_utc` is authoritative for
  when the version became active.

## Without the admin UI

On installs where the management UI is switched off
(`MMWP_TERMS_DISABLE_ADMIN_UI`), there is no acceptance log screen and no CSV
button. Either re-enable it temporarily on the install, or read the tables
directly:

```bash
wp db query "SELECT a.*, v.version_number, v.effective_date \
  FROM wp_mmwp_terms_acceptances a \
  LEFT JOIN wp_mmwp_terms_versions v ON v.id = a.version_id \
  WHERE a.user_id = 123 ORDER BY a.id DESC" --skip-column-names
```

Reading the table directly gives you no integrity verification. Re-enable the
UI and run the check, or verify the signatures separately, before relying on
what you pull out this way.

The version archive itself is reachable without the UI:

```bash
wp mmwp terms list
wp mmwp terms get --format=json
```
