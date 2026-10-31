# Retention policy — acceptance records

## The rule

| Data | Retention |
|---|---|
| Acceptance record (user, version, timestamps, action, site) | While the account exists, then a dispute period (default **5 years**). Retained thereafter in anonymised form |
| IP address, IP source, User-Agent | Erased once the dispute period elapses |
| Published versions of the terms, including full text | **Never deleted** |
| Audit events (PDF generated, email sent/failed, anonymisation, downloads) | Retained with the records they describe |

Records are **anonymised, never deleted**. The identifying context is cleared;
the fact of acceptance, the version accepted and the time it happened remain.

## Why these periods

Neither the GDPR nor Greek law sets a retention period for acceptance logs of
this kind. Art. 5(1)(e) therefore requires the controller to choose one and be
able to justify it — which is what this document is for.

**While the account exists.** The purpose — proving the terms governing an
active relationship were accepted — is live for as long as the relationship is.

**Plus a dispute period.** The default of 5 years tracks the general
limitation period for contractual claims under Greek civil law (Art. 250 of
the Civil Code): the window in which a claim could still be brought that the
record would answer. Once no claim can be brought, the identifying context has
outlived its purpose. **[operator]** Review this against your own exposure; a
different figure is fine if you can justify it, and this file is where the
justification belongs.

**Anonymisation rather than deletion.** Deleting the record would destroy the
accountability evidence that the acceptance mechanism functioned at all —
which is itself something a controller may have to demonstrate, and which no
longer requires identifying anyone. Clearing the IP address and User-Agent
removes the data that identifies a person beyond the account itself. This is
minimisation applied to the fields that need it, not to the record as a whole.

**Versions are never deleted.** An acceptance record whose text cannot be
reproduced proves very little: it shows someone clicked accept on *something*.
The archive is what turns a record into evidence, and it holds no personal
data, so there is nothing to minimise.

## How it is applied

- A daily scheduled task (`mmwp_terms_retention_sweep`) anonymises expired
  records in batches.
- Deleting a WP user triggers the same treatment for records already past the
  period, and writes a `user_deleted` audit event.
- An erasure request anonymises any of the requester's records that are
  already past the period, and explains the retention position for the rest.
- Every anonymisation writes an `anonymised` audit event recording the reason.
- Anonymisation clears the record's context signature along with the values.
  The record's own signature is unaffected and keeps verifying — the two are
  separate precisely so that a lawful minimisation action cannot be mistaken
  for tampering.

## Configuration

Settings → Terms & Conditions → Retention:

- **Dispute period (years)** — the window after which context is erased.
- **Anonymisation** — turn the sweep off entirely (records then keep their
  context indefinitely, which needs its own justification).

In code:

```php
add_filter( 'mmwp_terms_retention_settings', function ( array $settings ) {
    $settings['dispute_period_years'] = 7;
    return $settings;
} );
```

## Erasure requests

The right to erasure is restricted here, and the restriction is explained to
the requester rather than applied silently. The processing rests on
Art. 6(1)(b) and 6(1)(f), not consent, and Art. 17(3)(b) and (e) disapply the
right where processing is necessary for compliance with a legal obligation and
for the establishment, exercise or defence of legal claims. Proof that the
applicable terms were accepted is that, exactly.

WordPress's Tools → Erase Personal Data returns the records as *retained*,
with the reasoning, and erases the context of any record already past its
retention period.
