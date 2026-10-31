# Programmatic API reference

The supported programmatic surface of `mammoth-acceptance-logs`: `Api\TermsApi`
and its REST, WP-CLI and helper-function wrappers, plus every action and
filter the plugin fires.

## Programmatic management

Every route below goes through the same code path, so a version published by
any of them is archived, numbered and audited identically. All of them keep
working with the admin UI switched off — a locked-down install is precisely
the one that has to be driven by code.

**Publishing re-prompts everyone in scope.** That is the point, but it means
publishing on every deploy when nothing changed would nag your users into
ignoring the dialog. Use the "only if changed" variants in automation.

### Version numbers are derived, never inherited

If you publish without a `version_number`, one is derived — `1.0` becomes
`1.1`, `3` becomes `4`, and a non-numeric or absent number falls back to a
dated identifier, stepping until it finds an unused one. It is never copied
from the version being replaced: two different documents sharing a number is
the exact ambiguity the archive exists to remove, and the settings screen
already refuses to allow it.

The **effective date is never inherited either**. It is a statement about a
specific document, so a version published without one simply has none
recorded, rather than borrowing a date that belonged to different text.

### PHP

```php
// Publish, asking everyone in scope to accept.
$result = mmwp_terms_publish_version( $html, array(
    'version_number' => '2.1',
    'effective_date' => '2026-10-01',
    'change_summary' => 'Clarified section 4.',
) );

if ( is_wp_error( $result ) ) {
    // Duplicate version number, empty content, bad date, or a failed write.
}

// For sync jobs: no-op when the text already matches the active version.
mmwp_terms_publish_version_if_changed( $html, array( 'version_number' => '2.1' ) );

// Re-read the content source now instead of waiting for the daily cron.
$new_version_id = mmwp_terms_refresh();     // null if nothing changed

mmwp_terms_active_version();                // array|null
mmwp_terms_user_status( $user_id );         // accepted|pending|not_required
```

Call these on or after `plugins_loaded`.

### WP-CLI, REST and Abilities

These three are declared once with wp-kit (`Api\TermsResource`) and share one
validation and authorisation path. Commands, routes and abilities are listed in
[usage.md](usage.md). Permissions are decided by wp-kit's capability resolver
(`edit_posts` for reads, `manage_options` for publishing, plugin managers for the
acceptance listing) and can be adjusted with its `mmwp_kit_operation_capability` filter.

### Public read routes

Publishing your terms on a public page needs the text readable without a
login, which the routes above deliberately are not. A second, narrower set
covers that — read-only, versions only, and redacted:

| Route | Method | Purpose |
|---|---|---|
| `/public/version` | GET | The active version, with its full text |
| `/public/versions` | GET | The archive, newest first, *without* the text (`per_page`, `offset`) |
| `/public/versions/<id>` | GET | One version, with its full text |

```
curl https://example.com/wp-json/mmwp/v1/public/version
```

Each response carries `id`, `version_number`, `label`, `effective_date`,
`change_summary` and `is_active`, plus `content` on the single-version routes.
`version_hash`, `source`, `created_at_utc` and the activation window are
**not** exposed: they are internal provenance for whoever audits the archive,
not information a visitor reading the terms needs. Responses are anonymous and
sent with `Cache-Control: public, max-age=300`.

Set the `mmwp_terms_public_api_enabled` filter to `false` to remove these
routes entirely — they then 404 rather than 401, so an install whose terms are
internal does not advertise that the endpoint exists. Doing so also leaves the
Terms & Conditions block with nothing to fetch.

## Actions

| Hook | Fires when | Args |
|---|---|---|
| `mmwp_terms_accepted` | a user accepts | `$user_id, $version_hash, $acceptance_id, $version_id` |
| `mmwp_terms_record_failed` | an acceptance could **not** be persisted | `$user_id, $version_id, $db_error` |
| `mmwp_terms_skipped` | a user clicks "skip for now" | `$user_id` |
| `mmwp_terms_version_published` | a new version is archived and activated | `$version_id, $version_hash` |
| `mmwp_terms_version_changed` | the content hash changes (save or cron) | `$old_hash, $new_hash` |
| `mmwp_terms_content_saved` | the settings page is saved | `$version_hash, $meta` |
| `mmwp_terms_delivery_status_recorded` | integration point for ESP delivery webhooks | `$acceptance_id, $provider_message_id, $status` |

Hook `mmwp_terms_record_failed` and alert someone. Silently losing acceptance
evidence is the worst failure this plugin can have.

## Filters

| Filter | Purpose | Default |
|---|---|---|
| `mmwp_terms_enable_admin_ui` | Remove the whole management UI. See "Headless deployment". | `true` |
| `mmwp_terms_can_manage` | Override who may manage the plugin. Cannot re-enable anything while the kill-switch is on. | in the manager list |
| `mmwp_terms_eligible_managers` | The users offered when appointing a manager. | administrators |
| `mmwp_terms_trusted_proxies` | Proxies whose forwarded-IP header may be trusted (IPs or CIDR ranges). | `[]` |
| `mmwp_terms_ip_header` | Which `$_SERVER` key carries the forwarded client IP. | `HTTP_X_FORWARDED_FOR` |
| `mmwp_terms_site_identifier` | The source application identifier stored on each record. | host + blog ID |
| `mmwp_terms_retention_settings` | Dispute period and whether anonymisation runs. | 5 years, enabled |
| `mmwp_terms_content_source` | Swap the content source entirely — a JSON file, a CPT, an external legal CMS. | the `mmwp_terms_content` option |
| `mmwp_terms_version_meta` | Supply the version number, effective date and change summary for a publication. Runs last and wins — see "Pulling from an external system". | `[]` |
| `mmwp_terms_public_api_enabled` | Whether the unauthenticated `/public/*` read routes are registered at all. The Terms & Conditions block depends on them. | `true` |
| `mmwp_terms_excluded_roles` | Role slugs exempt from the gate. | `[]` |
| `mmwp_terms_excluded_users` | Individual user IDs exempt from the gate. | `[]` |
| `mmwp_terms_send_pdf_email` | Disable the emailed PDF receipt. `($enabled, $user_id)`. | `true` |
| `mmwp_terms_notify_admin_email` | Copy every acceptance notification to this address. | `''` |
| `mmwp_terms_skip_duration` | How long "skip for now" suppresses the overlay. The admin notice is unaffected. | `12 * HOUR_IN_SECONDS` |
| `mmwp_terms_drop_data_on_uninstall` | Whether deleting the plugin also drops the three tables and the acceptance markers. Return false to keep the evidence. | `true` |

### Example: source the terms from a JSON file instead of the settings page

```php
add_filter( 'mmwp_terms_content_source', function ( string $content ) {
    $path = WP_CONTENT_DIR . '/mmwp-terms/terms.json';
    if ( ! is_readable( $path ) ) {
        return $content;
    }
    $data = json_decode( (string) file_get_contents( $path ), true );
    return (string) ( $data['content'] ?? $content );
} );
```

The daily cron notices the change and archives the new version automatically,
so acceptances against it still point at preserved text.

### Example: exclude WooCommerce customers, keep staff roles gated

```php
add_filter( 'mmwp_terms_excluded_roles', fn ( array $roles ) => array_merge( $roles, [ 'customer' ] ) );
```

### Example: record provider-side delivery status from an ESP webhook

```php
// The plugin cannot poll your provider; wire its webhook to this action.
do_action( 'mmwp_terms_delivery_status_recorded', $acceptance_id, $message_id, 'delivered' );
```
