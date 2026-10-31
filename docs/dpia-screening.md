# DPIA screening — Terms & Conditions acceptance

**Status:** screening assessment (Art. 35(1) GDPR). Not a full DPIA.
**Scope:** the processing performed by the `mammoth-acceptance-logs` plugin.
**Owner:** the site operator, as controller. Review whenever the processing changes.

> This document is a template completed with everything the plugin itself
> determines. Items marked **[operator]** can only be answered by the
> organisation running the site and must be filled in before the screening
> can be relied on.

## 1. Description of the processing

Authenticated users of a WordPress installation are shown the applicable
Terms & Conditions and must accept them to continue using their account.
Each acceptance is recorded so the operator can later establish which version
of the terms was agreed, by whom, and when.

| | |
|---|---|
| Nature | Recording an act of acceptance against an authenticated account |
| Scope | Users in the configured roles; no guests, no anonymous visitors |
| Context | Internal staff, contractors, and subscriber staff on provisioned installs |
| Purposes | Proof of acceptance; audit logging; platform security and access control |

### Data recorded per acceptance

User ID; username and email address as at the moment of acceptance; UTC
timestamp plus the local time, timezone and UTC offset displayed to the user;
version ID, version number, effective date and content hash; the action taken;
site/application identifier; full IP address and its source; User-Agent string.

Supporting records: every published version of the terms, retained in full and
never deleted; and audit events covering PDF generation, email dispatch
outcome, version activation, anonymisation, log export and snapshot download.

### Legal bases

- **Art. 6(1)(b)** — acceptance is necessary for performance of the employment
  or service contract and for providing access to the platform.
- **Art. 6(1)(f)** — legitimate interests in security, accountability,
  auditability, and the ability to establish and defend what was agreed.

Consent (Art. 6(1)(a)) is **not** relied on and would be the wrong basis. For
employees and contractors the imbalance of power in the relationship makes
freely-given consent doubtful, and the HDPA (Decision 26/2019) expects a
controller to identify the correct basis at the outset rather than presenting
processing as consent where another basis actually applies. This also means
acceptance is not withdrawable in the Art. 7(3) sense; see
[retention-policy.md](retention-policy.md) for how erasure requests are handled.

## 2. Necessity and proportionality

| Data | Why it is necessary | Proportionality |
|---|---|---|
| User ID + username/email snapshot | Identifies who accepted; snapshots keep the record readable after the account is deleted | Minimal; already held for the account |
| UTC timestamp + local time/offset | Establishes when, unambiguously and across timezone changes | Minimal |
| Version ID, number, effective date, hash | Establishes *what* was accepted | Minimal |
| Preserved version text | A hash proves a version existed; only the text proves what the user saw | Not personal data |
| Full IP address | Corroborates the circumstances; the primary technical evidence that the acceptance came from where it claims | See §3 |
| User-Agent | Corroborating only; assists forensic reconstruction | Supplementary, never primary proof |
| Site identifier | Names which install the acceptance was made on | Minimal |

## 3. Balancing test — retention of the full IP address (Art. 6(1)(f))

**Legitimate interest.** Establishing that a specific acceptance genuinely
occurred, investigating disputed or repudiated acceptances, and detecting
abuse of the acceptance mechanism.

**Necessity.** A truncated or partially zeroed address materially reduces
evidential value: it cannot distinguish between hosts behind the same prefix,
which is precisely the distinction a repudiation dispute turns on. No less
intrusive measure achieves the purpose.

**Balance.** The processing is narrow and predictable: it is tied to a
deliberate act by an authenticated business user, on a platform they use for
work, in a context where they expect their actions to be logged. There is no
profiling, no behavioural tracking, no cross-site correlation and no automated
decision-making. The address is disclosed to no third party. It is erased once
the documented retention period elapses, while the accountability record
survives. Data subjects are told at the point of collection (a notice in the
acceptance dialog) and in the privacy notice.

**Conclusion.** Retention of the full address is proportionate for the
duration of the retention period, and is minimised by anonymisation
thereafter. This is the item most likely to be challenged; the mitigation is
the defined retention window and the transparency at collection.

## 4. Processing deliberately rejected

Recording a decision *not* to do something is itself accountability evidence.

| Rejected | Reasoning |
|---|---|
| Device fingerprinting (canvas, fonts, entropy) | Substantially more intrusive, and disproportionate to proving acceptance of platform terms. User ID + timestamp + version + preserved text + IP already provide stronger legal value than a fingerprint. No Greek statutory requirement makes it expected for this purpose. |
| MAC address | Not obtainable through ordinary browser-based authentication; not a required evidentiary field. |
| IP geolocation | Adds inferred location data without adding evidential weight. |
| Behavioural or usage analytics | Outside the purpose entirely. |

Introducing any of these would materially change this assessment and require
it to be redone.

## 5. Art. 35(3) and WP29 criteria

| Criterion | Present | Note |
|---|---|---|
| Evaluation or scoring | No | |
| Automated decision-making with legal effect | No | Acceptance gates access, but the decision is the user's own act, not an automated inference |
| Systematic monitoring | No | A discrete record per acceptance event; no ongoing observation |
| Sensitive data / Art. 9 or 10 data | No | |
| Data processed on a large scale | **[operator]** | Assess against your user population. An internal staff population is not "large scale"; a mass-consumer deployment may be |
| Matching or combining datasets | No | |
| Data concerning vulnerable subjects | **[operator]** | Employees are treated as vulnerable in the employment context because of the power imbalance — which is exactly why consent is not the basis here. Weigh alongside the narrowness of the processing |
| Innovative use or new technology | No | Conventional database logging |
| Preventing data subjects exercising a right | Partly | Erasure is restricted under Art. 17(3) for the retention period. Restriction is explained to the data subject rather than applied silently |

Two criteria met usually indicates a DPIA is required. On the plugin's own
facts, at most one clearly applies.

## 6. HDPA list of processing requiring a DPIA

Checked against **Decision 65/2018 (ΦΕΚ Β' 1622/10-5-2019)**, the Hellenic DPA's
list of processing operations subject to the DPIA requirement. **[operator]**
Confirm against the current text of the list, and re-confirm on any material
change to the processing.

On the plugin's own facts the processing does not involve large-scale
systematic monitoring of a publicly accessible area, large-scale special
category data, biometric or genetic data, systematic evaluation or scoring,
data matching across sources, innovative technologies, or processing that
prevents data subjects from exercising a right or accessing a service — the
service is provided *upon* acceptance, and acceptance is a contractual
precondition rather than a data-processing condition.

## 7. Conclusion

A full DPIA does **not** appear to be mandatory for the processing as
implemented, provided it remains limited to acceptance logging, account
identifiers, timestamps, version identifiers and security-related audit data.

This screening should be repeated, and a full DPIA seriously considered, if
any of the following are introduced: device fingerprinting, behavioural
analytics, cross-platform profiling, extensive monitoring, special-category
data, or deployment at consumer scale.

## 8. Residual risks and mitigations

| Risk | Mitigation |
|---|---|
| IP address retained longer than necessary | Documented retention period; automated anonymisation sweep; erasure request triggers early anonymisation of expired records |
| Audit records altered to fabricate or deny an acceptance | HMAC signature per record plus a hash chain; no update or delete path in the code or the UI; integrity verification available in the admin |
| Signing key compromised together with the database | Key can be placed in `wp-config.php`, outside the database. **This is tamper-evidence, not tamper-proofing** — see [evidence-handling.md](evidence-handling.md) |
| Acceptance evidence silently lost on a write failure | The write result is checked; failure blocks the acceptance, is reported to the user, and fires `mmwp_terms_record_failed` |
| Acceptance recorded against text the user never saw | The displayed text is served from the archive, and the version the browser displayed is verified on submission |
| Receipt email exposes personal data to a processor | **[operator]** Identify your mail provider and its transfer position in [ropa.md](ropa.md) |
| Proxy address logged instead of the client's | Configure `mmwp_terms_trusted_proxies` when behind a CDN or load balancer |
