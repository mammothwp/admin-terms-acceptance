# Usage

## Admin

1. **Settings → Terms & Conditions**: write the terms, choose the roles that
   must accept, publish the version.
2. **Users → Terms acceptance log**: the evidence, with signature verification.
   Only plugin managers can open it — being an administrator is not enough.

Users in scope see the popup on their next page load while logged in.

## REST (`mmwp/v1`)

Authenticate like any WordPress request (cookie + nonce, or an application
password). Routes are declared once through wp-kit.

| Method and route | Who |
|---|---|
| `GET /mmwp/v1/terms/version` | `edit_posts` |
| `GET /mmwp/v1/terms/versions?limit=&offset=` | `edit_posts` |
| `GET /mmwp/v1/terms/versions/<id>` | `edit_posts` |
| `POST /mmwp/v1/terms/version` (`content`, `version_number`, `effective_date`, `change_summary`, `only_if_changed`) | `manage_options` |
| `POST /mmwp/v1/terms/refresh` | `manage_options` |
| `GET /mmwp/v1/terms/acceptances?user_id=&version_id=&date_from=&date_to=&limit=&offset=` | plugin managers |

The acceptance listing is read-only and redacted: no IP address, User-Agent or
e-mail. The only unauthenticated routes are read-only version routes under
`/mmwp/v1/public/` (redacted, no acceptance records); disable them with
`mmwp_terms_public_api_enabled`.

## WP-CLI

```bash
wp mmwp terms get
wp mmwp terms list --limit=10 --format=json
wp mmwp terms show 3
wp mmwp terms publish --content="$(cat terms.html)" --version-number=2.1 --effective-date=2026-10-01 --change-summary="Clarified section 4." --only-if-changed
wp mmwp terms refresh
wp mmwp terms acceptances --version-id=3 --format=csv
wp mmwp terms purge --dry-run        # report evidence; add --force to delete it permanently
```

Purge is CLI-only: deleting evidence is deliberately not offered over REST or
abilities.

## Abilities API (WordPress 6.9+)

Category `mmwp-acceptance-logs`: `get-active-version`, `list-versions`,
`get-version`, `publish-version`, `refresh-content` and the read-only
`list-acceptances`. Each carries its field schema and read/write annotations
and enforces the same capability as REST.

## PHP

`mmwp_terms_*()` helpers in `src/api-functions.php` and `Api\TermsApi` are the
supported programmatic entry points. See `programmatic-api.md`.

## Block

Insert **Terms & Conditions** (`mmwp/terms-display`) on a public page to show
the current version, its effective date and history.
