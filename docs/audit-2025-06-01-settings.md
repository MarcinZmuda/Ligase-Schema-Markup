# Settings integration audit - 2025-06-01

Scope: every UI surface that registers a field, meta_key, or option, traced
end-to-end against sanitize/save and the readers that consume the value.
Tracks both directions:

- ghost write: rendered + accepted by browser, but value never persists
- ghost read:  value is consumed somewhere but no UI persists it
- dead config: value persists but nothing reads it

All line numbers verified against current `admin/`, `includes/`, `admin/views/`.

## CRITICAL ghost settings (render, save fails OR never read)

1. `default_schema_type` (option key) - GHOST WRITE
   - Registered: [admin/class-settings.php:96](../admin/class-settings.php#L96) (`schema_type_select` field, Behavior section)
   - Default declared: [class-settings.php:745](../admin/class-settings.php#L745)
   - Read by: [admin/views/posts.php:330](../admin/views/posts.php#L330), [admin/views/posts.php:154](../admin/views/posts.php#L154), [admin/views/meta-box.php:16](../admin/views/meta-box.php#L16), [includes/types/class-blogposting.php:44](../includes/types/class-blogposting.php#L44), [includes/class-ajax.php:1296](../includes/class-ajax.php#L1296) (AJAX bulk does its own write)
   - NOT in `sanitize()` text_fields / url_fields / checkboxes / numbers ([class-settings.php:593-655](../admin/class-settings.php#L593-L655)).
   - Impact: when user opens Ustawienia tab and clicks Save, this entire section is submitted. `default_schema_type` is not whitelisted, so the merge in `sanitize()` lets the previous value survive ONLY because of the 2.4.x `array_merge($defaults, $current)` fix. The dropdown on the Behavior tab visibly changes value, user clicks Save - new selection is silently discarded; value reverts to whatever the AJAX bulk-tool last wrote (or the literal default `BlogPosting`). Classic same-shape bug as `org_author_mode` / `lb_service_area`. Already noted in `docs/audit-history/AUDIT_CORE_SECURITY.md` issue #9.
   - Fix: add `'default_schema_type'` to the text_fields list AND validate against `array('Article','BlogPosting','NewsArticle','TechArticle','LiveBlogPosting')` (same whitelist as [class-ajax.php:1214](../includes/class-ajax.php#L1214) and [class-admin.php:329](../admin/class-admin.php#L329)).

2. `health_report_enabled` (option key) - GHOST WRITE (asymmetric: enabling works once, disabling never persists)
   - UI: [admin/views/tools.php:121](../admin/views/tools.php#L121) - standalone checkbox inside its own `<form action="options.php">` with `settings_fields('ligase_settings_group')`.
   - Default: not declared in `defaults()`.
   - Read by: [includes/class-health-report.php:43](../includes/class-health-report.php#L43) (`if ( empty( $opts['health_report_enabled'] ) ) { return; }`).
   - NOT in any sanitize whitelist - `sanitize()` does not touch it, does not normalise it to '' on missing.
   - Impact: ticking + Save with checkbox set sends `ligase_options[health_report_enabled]=1` -> survives via the `array_merge($defaults, $current, $input)` overlay so the option is written as '1'. But unchecking + Save sends no `health_report_enabled` key at all -> `sanitize()` does not zero it out -> previous '1' persists forever from `$current`. User cannot turn off weekly emails via the UI.
   - Fix: add `'health_report_enabled'` to the explicit checkbox loop at [class-settings.php:646-655](../admin/class-settings.php#L646-L655) so any submit through `ligase_settings_group` normalises it to '' / '1'.
   - Side note: the `tools.php` view is officially retired (admin menu removed in 2.4.3 per [class-admin.php:95-97](../admin/class-admin.php#L95-L97)) but the file is kept on disk and the URL `?page=ligase-narzedzia` is still routed in [class-admin.php:177](../admin/class-admin.php#L177). So the ghost is reachable.

3. `lb_service_area` (option key) - DEAD CONFIG (save fixed, but nothing reads it)
   - Registered: [class-settings.php:110](../admin/class-settings.php#L110) checkbox.
   - In sanitize checkbox loop: [class-settings.php:652](../admin/class-settings.php#L652) (correctly added in 2.4.x).
   - Default: [class-settings.php:747](../admin/class-settings.php#L747).
   - UI description (the user-visible promise): "Wlacz dla firm bez stalej siedziby... Ligase uzyje pola Area served zamiast adresu." [class-settings.php:561](../admin/class-settings.php#L561).
   - READ BY: nothing. `grep lb_service_area includes/` returns zero hits.
   - In particular, [includes/types/class-localbusiness.php:241](../includes/types/class-localbusiness.php#L241) `has_address()` only checks `lb_street && lb_city`, ignoring the flag. A service-area firm that ticks the box AND fills only `lb_area_served` still emits no LocalBusiness node because `has_address()` returns false. The promise in the description is unimplemented.
   - Fix: either (a) make `Ligase_Type_LocalBusiness::build()` accept `lb_service_area=1 && lb_area_served` as sufficient and skip the address-required gate, or (b) hide the checkbox and rephrase the area_served field. Today the checkbox exists, persists, and changes literally nothing.

4. `_ligase_enable_audio` (post meta key) - DEAD CONFIG and ORPHAN-EXPOSED-IN-RULES
   - Declared as a targetable schema in [includes/class-schema-rules.php:35](../includes/class-schema-rules.php#L35) `'AudioObject' => '_ligase_enable_audio'`.
   - Rendered by [admin/views/rules.php:205-211](../admin/views/rules.php#L205-L211) (`foreach ( $schema_types as $label => $meta_key )`), so admins can build a rule "enable AudioObject for category=podcast".
   - READ BY: nothing. `grep _ligase_enable_audio` returns only the SCHEMA_TYPES declaration.
   - [includes/types/class-audioobject.php](../includes/types/class-audioobject.php) does not gate on `_ligase_enable_audio` at all - it checks `_ligase_audio` meta or auto-detects Spotify/Buzzsprout embeds ([class-audioobject.php:14-25](../includes/types/class-audioobject.php#L14-L25)).
   - Impact: a Rule "for category=podcast emit AudioObject" writes `_ligase_enable_audio=1` to matching posts, then the AudioObject builder ignores that flag entirely. The rules engine appears to work but is a no-op for this schema type. NOTE: the toggle is also NOT in the meta-box `$toggles` array ([meta-box.php:20-77](../admin/views/meta-box.php#L20-L77)), so the per-post checkbox doesn't exist either - only Rules surface it.
   - Fix: either drop AudioObject from `SCHEMA_TYPES`, or have `Ligase_Type_AudioObject::build()` honour `_ligase_enable_audio` as a force-flag fallback before returning null.

5. `_ligase_audio` and `_ligase_audio_duration` (post meta) - ORPHAN READS
   - Read by [includes/types/class-audioobject.php:15](../includes/types/class-audioobject.php#L15) and [class-audioobject.php:76](../includes/types/class-audioobject.php#L76).
   - Saved by: nothing. No metabox field, no AJAX endpoint, no Gutenberg block, no save_meta_box branch.
   - Impact: the manual-meta path is dead. AudioObject only works via Spotify/Buzzsprout auto-detect. Power users have no way to add a manual podcast episode.

6. `organization_knows_about` (option key TYPO) - ORPHAN READ pointing at a non-existent key
   - Read by [includes/class-score.php:863](../includes/class-score.php#L863) inside `check_knows_about()`.
   - Real key is `knows_about` (declared, sanitised, defaulted, and used by [class-organization.php:52](../includes/types/class-organization.php#L52)).
   - Impact: the Schema Score check `site_knows_about` will ALWAYS return `passed=false`, regardless of whether the user filled the field. Site-wide score is permanently penalised for this item. Author-level `author_knows_about` check works correctly because it reads `ligase_knows_about` user_meta.
   - Fix: change to `$this->options['knows_about'] ?? ''`.

## Suspicious — saved but never read (dead config)

(Covered above: `lb_service_area`, `_ligase_enable_audio`.)

7. `ligase_twitter` (user_meta) - WRITTEN by importer only, never via UI
   - Written by [admin/class-admin.php:825](../admin/class-admin.php#L825) `$url_fields` loop AND by [includes/class-importer.php:157](../includes/class-importer.php#L157).
   - But render_author_fields does NOT include `ligase_twitter` in its `$fields` array ([class-admin.php:683-738](../admin/class-admin.php#L683-L738)) - no input is rendered, so the save loop's `isset($_POST['ligase_twitter'])` is always false during a normal profile save. Effectively only writable via importer.
   - Read by [includes/types/class-person.php:326](../includes/types/class-person.php#L326), [includes/class-score.php:597](../includes/class-score.php#L597), and importer for de-dup.
   - Severity: low - intentional legacy fallback per the comment, but the listing in `$url_fields` (save loop) is misleading and looks like a regression-trap if someone copies the pattern.
   - Fix (optional): drop from `$url_fields`, or re-expose as a render field with `(legacy)` hint matching `ligase_linkedin` (which IS rendered at [class-admin.php:730](../admin/class-admin.php#L730)).

8. `ligase_credential` (singular, user_meta) - LEGACY orphan write
   - Listed in save_author_fields `$text_fields` [class-admin.php:815](../admin/class-admin.php#L815) (with "kept for backward compat" comment).
   - No render input. Read by [includes/types/class-person.php:138](../includes/types/class-person.php#L138) as a fallback when `ligase_credentials` (plural) is empty.
   - Severity: harmless (legacy fallback), but same pattern as #7 - listed in save loop, never enters the loop.

9. `_ligase_product` (post meta) - LEGACY orphan read; only override path is wired
   - Read by [includes/types/class-product.php:39](../includes/types/class-product.php#L39).
   - Save path: NO direct `update_post_meta($post_id, '_ligase_product', ...)` anywhere in `admin/`. The metabox Product section instead writes `_ligase_override[Product][...]` via the Field_Contract loop ([class-admin.php:578-625](../admin/class-admin.php#L578-L625)).
   - Severity: low - the resolver path handles overrides cleanly. Stale `_ligase_product` data from pre-2.4 installs would still be honoured. New installs can never populate this.
   - Fix: either re-introduce a UI for the legacy variant data, OR remove the legacy fallback at [class-product.php:39](../includes/types/class-product.php#L39) once data is confirmed migrated.

## OK — fully wired end-to-end (sanity-check positives)

### Organization section
- `org_name`, `org_description`, `org_phone`, `org_email`, `org_logo` - sanitised ([class-settings.php:593-628](../admin/class-settings.php#L593-L628)) and consumed by [class-organization.php:30-74](../includes/types/class-organization.php#L30-L74) and [class-localbusiness.php:160,175](../includes/types/class-localbusiness.php#L160).
- `knows_about` - read by [class-organization.php:52](../includes/types/class-organization.php#L52). (BUT see ghost #6 above for the broken score-side reader.)
- `logo_width`, `logo_height` - numeric sanitised, used by [class-organization.php:183-184](../includes/types/class-organization.php#L183).
- `org_author_mode` - checkbox sanitised at [class-settings.php:651](../admin/class-settings.php#L651), read by [class-generator.php:180](../includes/class-generator.php#L180) and [class-blogposting.php:25](../includes/types/class-blogposting.php#L25). (Was the 2.4.x regression that motivated this audit; correctly fixed.)

### Social section
- All six `social_*` URL fields sanitised ([class-settings.php:621-629](../admin/class-settings.php#L621-L629)), consumed by [class-organization.php:30-31](../includes/types/class-organization.php#L30) and [class-localbusiness.php:220-221](../includes/types/class-localbusiness.php#L220).

### Behavior section
- `standalone_mode`, `force_output`, `debug_mode` - checkboxes ([class-settings.php:647-649](../admin/class-settings.php#L647-L649)) read by [class-output.php:101-132](../includes/class-output.php#L101) and [class-logger.php:126](../includes/class-logger.php#L126).
- `speakable_selectors` - text sanitised, read by [class-blogposting.php:148](../includes/types/class-blogposting.php#L148) and [class-validator.php:158](../includes/class-validator.php#L158).

### LocalBusiness section
- `lb_type`, `lb_name`, `lb_description`, `lb_street`, `lb_city`, `lb_region`, `lb_postal`, `lb_country`, `lb_lat`, `lb_lng`, `lb_price_range`, `lb_area_served` - all sanitised at [class-settings.php:596-598](../admin/class-settings.php#L596-L598), all read by [class-localbusiness.php:140-269](../includes/types/class-localbusiness.php#L140).
- `lb_hours` - nested-array sanitiser at [class-settings.php:684-708](../admin/class-settings.php#L684), consumed at [class-localbusiness.php:200](../includes/types/class-localbusiness.php#L200).

### Store section
- `store_mode` - checkbox [class-settings.php:650](../admin/class-settings.php#L650), read by [class-organization.php:14](../includes/types/class-organization.php#L14).
- `store_currency` - 3-letter ISO validated [class-settings.php:666-669](../admin/class-settings.php#L666), used by Organization shipping, Product, Service.
- `store_return_country`, `store_return_days`, `store_return_fees`, `store_shipping_country`, `store_shipping_rate`, `store_handling_min/max`, `store_transit_min/max` - all sanitised and consumed by [class-organization.php:116-149](../includes/types/class-organization.php#L116) and [class-product.php:311,354](../includes/types/class-product.php#L311).

### NER section
- `ner_provider`, `ner_api_key` - sanitised, consumed by [class-ner-api.php:44-45](../includes/class-ner-api.php#L44).

### Author profile fields (user_meta) — all wired Person-side
- `ligase_is_redakcja` ([class-blogposting.php:28](../includes/types/class-blogposting.php#L28), [class-generator.php:183](../includes/class-generator.php#L183))
- `ligase_given_name`, `ligase_family_name`, `ligase_honorific`, `ligase_job_title`, `ligase_telephone`, `ligase_publish_email`, `ligase_knows_language`, `ligase_knows_about`, `ligase_alumni_of`, `ligase_alumni_of_url`, `ligase_alumni_of_dept`, `ligase_credentials`, `ligase_member_of`, `ligase_extra_sameas`, `ligase_linkedin`, `ligase_wikidata`, `ligase_image_url` - render + save + Person consumer all present.

### Post meta toggles (`_ligase_enable_*`)
Every entry in `Ligase_Schema_Rules::SCHEMA_TYPES` EXCEPT `_ligase_enable_audio` is gated by the corresponding type class:

| Toggle | UI | Save | Reader |
|--------|----|------|--------|
| `_ligase_enable_faq` | meta-box + posts.php bulk | save_meta_box[339] | [class-faqpage.php:12](../includes/types/class-faqpage.php#L12) |
| `_ligase_enable_howto` | meta-box + bulk | save | [class-howto.php:12](../includes/types/class-howto.php#L12) |
| `_ligase_enable_review` | meta-box + bulk | save | [class-review.php:27](../includes/types/class-review.php#L27) |
| `_ligase_enable_qapage` | meta-box + bulk | save | [class-qapage.php:14](../includes/types/class-qapage.php#L14) |
| `_ligase_enable_glossary` | meta-box + bulk | save | [class-definedterm.php:14](../includes/types/class-definedterm.php#L14) |
| `_ligase_enable_claimreview` | meta-box + bulk | save | [class-claimreview.php:37](../includes/types/class-claimreview.php#L37) |
| `_ligase_enable_software` | meta-box + bulk | save | [class-softwareapplication.php:14](../includes/types/class-softwareapplication.php#L14) |
| `_ligase_enable_course` | meta-box + bulk | save | [class-course.php:14](../includes/types/class-course.php#L14) |
| `_ligase_enable_event` | meta-box + bulk | save | [class-event.php:14](../includes/types/class-event.php#L14) |
| `_ligase_enable_service` | meta-box + bulk | save | [class-service.php:34](../includes/types/class-service.php#L34) (manual flag only - NO `is_enabled_for_post` rules-engine fallback) |
| `_ligase_enable_product` | meta-box + bulk | save | [class-product.php:32-33](../includes/types/class-product.php#L32) |
| `_ligase_enable_recipe` | meta-box + bulk | save | [class-recipe.php:27](../includes/types/class-recipe.php#L27) |
| `_ligase_enable_jobposting` | meta-box + bulk | save | [class-jobposting.php:31-32](../includes/types/class-jobposting.php#L31) |
| `_ligase_enable_forum` | meta-box + bulk | save | [class-discussionforumposting.php:35-36](../includes/types/class-discussionforumposting.php#L35) |
| `_ligase_enable_profile_page` | meta-box (pages only) + bulk | save | [class-generator.php:367](../includes/class-generator.php#L367) |
| `_ligase_paywalled` | meta-box (advanced fieldset) + bulk | save | [class-blogposting.php:83](../includes/types/class-blogposting.php#L83) |

Minor consistency hole inside the OK column: `_ligase_enable_service` is the only schema where the type class checks the post-meta flag but does NOT consult `Ligase_Schema_Rules::is_enabled_for_post(...)`. Every other type uses the `manual || rules` pattern. A Rule that enables Service for `category=services` will write `_ligase_enable_service=1` on matching posts and Service WILL appear there (because the meta is set), but a Rule with "always emit Service" without a condition won't work because the post-meta gate is hard. Not strictly a ghost - just an asymmetry. See [class-service.php:34](../includes/types/class-service.php#L34) vs all others.

### Structured per-type post meta
- `_ligase_service`, `_ligase_recipe`, `_ligase_jobposting`, `_ligase_howto`, `_ligase_faq_items`, `_ligase_citations`, `_ligase_override` - save_meta_box writes them ([class-admin.php:459-625](../admin/class-admin.php#L459)), type classes read them. `_ligase_howto` and `_ligase_faq_items` also written by the Gutenberg block bridge in [class-plugin.php:253-266](../includes/class-plugin.php#L253).
- `_ligase_schema_type` - 5-item whitelist applied at [class-admin.php:329-335](../admin/class-admin.php#L329), read by Generator + meta-box.
- `_ligase_force_date_modified`, `_ligase_dateline`, `_ligase_image_credit`, `_ligase_image_license`, `_ligase_image_acquire` - all saved by meta-box, read by [class-blogposting.php:75,210,321-325](../includes/types/class-blogposting.php#L75).
- `_ligase_paywall_selector` - saved as text_meta, read at [class-blogposting.php:87](../includes/types/class-blogposting.php#L87).
- `_ligase_profile_user_id` - saved at [class-admin.php:379-386](../admin/class-admin.php#L379), read at [class-generator.php:371](../includes/class-generator.php#L371).

## Priority fix order (top 10)

1. **`default_schema_type`** - add to `sanitize()` text_fields + enum-validate against the 5-item list. Same regression class as `org_author_mode`. (High user-visible impact: dropdown lies.)
2. **`health_report_enabled`** - add to checkbox loop in `sanitize()`. Users currently cannot disable weekly emails via UI.
3. **`organization_knows_about` typo** - one-character fix in `class-score.php:863` -> `'knows_about'`. Removes a permanent false-negative from every site's Schema Score.
4. **`_ligase_enable_audio` vs AudioObject** - either drop AudioObject from `SCHEMA_TYPES` or honour the flag in `class-audioobject.php::build()`. Rules-engine currently lies for this type.
5. **`lb_service_area`** - implement the documented behaviour in `class-localbusiness.php::has_address()` so the checkbox actually causes a no-address LocalBusiness with `areaServed`. Otherwise hide the checkbox.
6. **`_ligase_audio` / `_ligase_audio_duration` write path** - add a metabox section (or remove the orphan reads).
7. **Service rules-engine asymmetry** - `class-service.php:34` should mirror the other type classes and accept `Ligase_Schema_Rules::is_enabled_for_post('_ligase_enable_service', $post_id)` as a second gate.
8. **`ligase_twitter` user_meta** - either re-add the legacy URL input to render_author_fields (like `ligase_linkedin`) or drop it from `$url_fields` in save_author_fields.
9. **`ligase_credential` (singular)** - remove from `$text_fields` save loop; the Person fallback reader can stay as a one-time migration helper, but the save list entry is dead weight that invites bugs.
10. **Audit harden: a phpunit test** - iterate `add_settings_field` registrations, simulate a full POST, assert every registered field round-trips. This is the only structural defence against the `org_author_mode` / `lb_service_area` / `default_schema_type` pattern recurring.
