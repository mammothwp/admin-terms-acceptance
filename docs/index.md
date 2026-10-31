# MammothWP Acceptance Logs

Records that your logged-in users accepted your terms. Every acceptance is
tied to a permanent archive of the exact text that was shown, signed twice
(immutable core, erasable context) and chained to the previous record, so
later tampering is detectable.

- Role-scoped acceptance popup and admin notice.
- Versioned archive: versions are never deleted.
- Tamper-evident log with a verifier, emailed PDF receipt (optional dompdf).
- GDPR: personal-data exporter, eraser (retention anonymisation) and privacy
  policy text.
- One definition, four surfaces: admin screens, REST (`mmwp/v1`), WP-CLI
  (`wp mmwp terms`) and the WordPress Abilities API.

Not a consent tool: it is an access-control and contractual-compliance record
(GDPR Art. 6(1)(b) and (f)). See [privacy](privacy.md).

Pages: [usage](usage.md) · [hooks](hooks.md) · [privacy](privacy.md).
Requires WordPress 6.2+, PHP 8.1+.
