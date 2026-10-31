# Hooks reference

Every custom hook is prefixed `mmwp_terms_`. Hooks fired by wp-kit
(`mmwp_kit_operation_capability`, `mmwp_kit_operation_before_run`, `mmwp_kit_operation_result`,
…) apply to the REST/CLI/Ability surface; see the wp-kit `HOOKS.md`.

## Actions

| Hook | Params | Fired |
|---|---|---|
| `mmwp_terms_accepted` | `int $user_id, string $version_hash, int $acceptance_id, int $version_id` | After an acceptance record is written and the user marked as accepted. |
| `mmwp_terms_skipped` | `int $user_id` | When a user chooses "Skip for now". |
| `mmwp_terms_record_failed` | `int $user_id, ?int $version_id, string $error` | When the acceptance insert fails. The user is told; no user meta is written. |
| `mmwp_terms_version_published` | `int $version_id, string $hash` | After a version is archived and activated. |
| `mmwp_terms_version_changed` | `string $old_hash, string $new_hash` | When the active terms hash changes. |
| `mmwp_terms_delivery_status_recorded` | `int $acceptance_id, string $message_id, string $status` | Documented integration point: fire it from a mail provider webhook to record delivery of a receipt. |

## Filters

| Hook | Params | Default | Purpose |
|---|---|---|---|
| `mmwp_terms_content_source` | `string $default` | stored terms | Feed the terms from an external document. |
| `mmwp_terms_version_meta` | `array $meta, string $content, string $hash` | caller's meta | Last word on version number, effective date and summary. Check `$meta['source']`. |
| `mmwp_terms_excluded_roles` | `string[]` | `[]` | Roles never asked to accept. |
| `mmwp_terms_excluded_users` | `int[]` | `[]` | Users never asked to accept. |
| `mmwp_terms_skip_duration` | `int $seconds` | 12 hours | How long "Skip for now" lasts. |
| `mmwp_terms_send_pdf_email` | `bool $send, int $user_id` | `true` | Whether a receipt is e-mailed to the user. |
| `mmwp_terms_notify_admin_email` | `string $email` | `''` | Also e-mail receipts to this address. |
| `mmwp_terms_retention_settings` | `array $settings` | stored | Override retention settings. |
| `mmwp_terms_site_identifier` | `string $default` | site URL host | Identifier written to each record. |
| `mmwp_terms_ip_header` | `string $header` | `HTTP_X_FORWARDED_FOR` | Forwarded-IP header read from trusted proxies only. |
| `mmwp_terms_trusted_proxies` | `string[]` | `[]` | CIDR ranges allowed to set the forwarded header. Empty means the header is never trusted. |
| `mmwp_terms_can_manage` | `bool $allowed, int $user_id` | manager list | Map manager access onto another permission system. Cannot override the admin-UI kill-switch. |
| `mmwp_terms_eligible_managers` | `WP_User[]` | users with `manage_options` | Who can be added as a manager. |
| `mmwp_terms_enable_admin_ui` | `bool` | `true` | Return false to remove the whole management UI. |
| `mmwp_terms_public_api_enabled` | `bool` | `true` | Return false to unregister the public read-only routes. |
| `mmwp_terms_drop_data_on_uninstall` | `bool` | stored choice (keep) | Overrides the stored uninstall choice for the acceptance evidence. |

## Constants

| Constant | Purpose |
|---|---|
| `MMWP_TERMS_INTEGRITY_KEY` | Signing key kept outside the database (recommended). |
| `MMWP_TERMS_DISABLE_ADMIN_UI` | `true` removes the management UI. |
