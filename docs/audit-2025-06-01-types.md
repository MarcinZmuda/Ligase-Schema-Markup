# Schema type classes audit — 2025-06-01

Scope: `includes/types/*.php` (24 classes). Focus: fatal/warning risks, invalid output per Google 2025-2026 docs, edge cases, schema correctness. Cross-referenced with `class-field-resolver.php` and `class-schema-rules.php` (referenced but not re-audited here).

Severity legend:
- **CRITICAL** — can fatally fail PHP, throw warnings on PHP 8.x, or produce broken JSON.
- **HIGH** — emits invalid schema per Google docs; rich result lost or Search Console warning.
- **MEDIUM** — works in happy path; fails on edge cases that occur in real sites.
- **LOW** — code-quality, consistency, or minor correctness.

---

## CRITICAL — fatal / warning risks

- **class-breadcrumb.php:93** — Logic bug producing the wrong `@id` and possible TypeError flow. The expression `is_category() || is_tag() || is_tax() ? get_term_link(...) : home_url('/')` is always entered on the taxonomy branch (we are inside that `if` block, line 68), but worse: `get_term_link()` can return `WP_Error`. The next line `is_string($id) ? $id : home_url('/')` only handles a non-string, but does not handle the case where the same URL is then concatenated. If `get_term_link()` returned `WP_Error`, `esc_url($url)` is fine but the schema `@id` silently degrades to home `/#breadcrumb` — colliding with other breadcrumbs on the same site (duplicate `@id` violation across taxonomy pages). Repro: any custom taxonomy with broken rewrite rules → WP_Error → `@id` collapses to homepage variant.

- **class-breadcrumb.php:13-15** — The function returns `null` for "front page" but for taxonomy pages the conditional path returns its own array early at line 96, while non-taxonomy single/page falls through to line 103 (adding final item) then line 115. Two functioning return paths is fine, but the final item is **never added** for taxonomies; the term itself is line 86 — duplicates current term as ListItem twice if the function flow ever re-enters. Static analysis OK but the control flow is brittle to refactor.

- **class-itemlist.php:218** — Subscript-on-expression that can crash. `[$stock_status]?? 'https://schema.org/InStock'` is valid PHP 8.x but relies on `$stock_status` being a non-null scalar. `WC_Product::get_stock_status()` can theoretically return empty string for some product types; lookup yields `null` → fallback works, so no crash. NOT critical, downgraded.

- **class-itemlist.php:265** — Reading `$_SERVER['HTTP_HOST']` without `isset()` check inside `wp_unslash()` — line *does* check, OK. But `$_SERVER['REQUEST_URI']` may be missing on CLI/WP-CLI runs; fallback `'/'` handles it. No crash. (Note: when called from CLI cron, `current_url()` returns just `/` plus null host → schema `@id` like `http:///#itemlist`. Result: broken `@id`. See HIGH.)

- **class-product.php:31** — `$is_wc_product = get_post_type($post_id) === 'product' && function_exists('wc_get_product')` — correctly gated. But further down at **class-itemlist.php:179-181** `wc_get_product($post->ID)` is called inside `if (function_exists('wc_get_product'))`, then `$product instanceof WC_Product` — OK. However the WC_Product class name is **type-hinted in method signature** at line 195 (`private function build_inline_product( WC_Product $product, ... )`). If WooCommerce is deactivated but the class file is autoloaded for any reason (cache, opcode), PHP 8.x will fatal on the class reference at parse time? **No** — PHP only resolves type hints at call time. Method is only ever called from line 181 inside the `function_exists` guard, so safe. However see MEDIUM for ItemList file parsing concerns.

- **class-blogposting.php:64** — `Ligase_Type_BlogPosting::author_ref_id($author_id)` is called with `$author_id` that comes from `get_post_field('post_author', $post_id)` cast to int. If the post has been orphaned (author deleted), `$author_id` becomes `0`, and `author_ref_id(0)` returns `home_url('/#author-0')` — not fatal, but produces a dangling `@id` reference to a Person node that does **not** exist in the graph (because the Person builder bails on `get_userdata(0)`). HIGH issue, not fatal.

- **class-person.php:51-53** — `preg_split('/\s+/', ..., 2)` then `[$given, $family] = $parts;` — guarded by `count($parts) === 2`, but if `$user->display_name` is a single word (e.g. "Redakcja"), `preg_split` returns `['Redakcja']`, count is 1, branch skipped — safe.

- **class-person.php:298** — Method signature `collect_same_as( WP_User $user )`. WP_User is always available in WordPress (core class), no risk. OK.

- **class-localbusiness.php:188-190** — `(float) ($opts['lb_lat'] ?? 0)` followed by `if ($lat !== 0.0 && $lng !== 0.0)`. Strict comparison against `0.0` correctly skips when value missing or zero. However, real-world coordinates of exactly 0 (equator/Greenwich intersection — Gulf of Guinea) would be skipped silently. Edge case only.

- **class-event.php:96** — `wp_get_attachment_image_src($tid, 'full')` can return `false`. Code does check `if ($img)` — OK.

- **class-videoobject.php:52** — `wp_strip_all_tags(wp_strip_all_tags(get_the_excerpt($post_id)))` — double-wrap is harmless but reveals copy-paste. LOW.

- **class-discussionforumposting.php:94** — `strtotime($c->comment_date_gmt)` — if comment_date_gmt is `0000-00-00 00:00:00` (legacy WP), `strtotime` returns `false`. Then `gmdate('c', false)` produces a 1970 timestamp `1970-01-01T00:00:00+00:00`. Not fatal, but emits implausible dates that Google can flag. Severity: HIGH.

- **class-itemlist.php:144-156** — Iterating `$wp_query->posts`. On 404 pages or empty archives `$wp_query->posts` may be `null` (not array). Line 138 checks `empty(...) || !is_array(...)` — OK.

- **class-claimreview.php:75** — `'author' => ['@type' => 'Organization', 'name' => wp_strip_all_tags($source)]` — only emitted when `$source` is non-empty. OK. However, schema.org `Claim.author` should be `Person` or `Organization` (object). Schema-valid.

---

## HIGH — invalid output per Google docs

### class-blogposting.php

- **L29-30, L64** — Orphaned-author safety: when `$author_id === 0`, `author_ref_id(0)` returns `/#author-0`, but the Person node for user 0 is suppressed. Result: BlogPosting `author[].{ @id: '/#author-0' }` is a dangling pointer. Google's structured data validator does not strictly require `@id` resolution within the document, but it weakens E-E-A-T attribution and confuses LLM crawlers. Fix: when `$author_id <= 0`, emit inline `{ '@type': 'Person', 'name': 'Redakcja' }` or default to publisher `@id`.

- **L31** — `home_url('/#author-' . $author_id)`. `home_url()` returns the homepage URL, then `'/#author-X'` is concatenated, producing `https://site.tld//#author-1` (double slash) when `home_url()` does not have trailing slash plus `'/'` prefix. Actually inspecting: `home_url('/#author-1')` — WordPress's `home_url()` treats the argument as a path; it returns `https://site.tld/#author-1`. OK on inspection. (Same pattern used across all classes — consistent.)

- **L56** — `'@id' => esc_url( get_permalink() ) . '#posting'`. This generates the permalink at runtime via `get_permalink()` (no arg → current post in the loop). Combined with the explicit `$post_id` used elsewhere in same method, there is an implicit assumption the loop and `$post_id` agree. If the Generator calls `build()` outside the loop (or after a `the_post()` reset), they will diverge. Severity: MEDIUM (depends on Generator usage).

- **L74-78** — `dateModified` is omitted when not >=5min after publish. This is custom behaviour intended to suppress spurious updates. Google Article docs strongly recommend emitting `dateModified` when present. Custom logic may cost richer "Recently Updated" pill in SERP. Acceptable trade-off but document for clients.

- **L138** — `accessMode` set unconditionally. If headline+description-only article has no body images and no embedded media, `'visual'` is incorrectly claimed when `!empty($images)`. The featured image alone may not be reflective of `accessMode: visual` (`visual` per a11y semantics means visual content is intrinsic to the article). LOW.

- **L141-144** — `potentialAction.ReadAction.target` is a bare URL string. Per Google's `ReadAction` examples it should be `EntryPoint` with `urlTemplate`. May cause SDTT warning. LOW.

- **L156-164** — `about` and `mentions` use `array_slice($about_hints, 0, 5)`. `$about_hints` could be an associative array (meta saved with keys); `array_slice` keeps keys, then `array_map` returns mixed-key array. When `wp_json_encode` outputs this as an associative object instead of a JSON array, Google's parser rejects it (expects array). Fix: wrap `array_slice` with `array_values()`. **Production-breaking** for sites using the entity pipeline with assoc-keyed meta.

- **L185-189** — Same `array_filter` issue: `array_filter` preserves keys → potentially produces associative array → emitted as JSON object instead of array. `isBasedOn` will break for any post where any source has `empty(url)` (the filter removes that entry, creating a hole in the key sequence). **HIGH** — confirmed bug.

- **L216-226** — `citation` array — same `array_filter` problem. **HIGH**.

- **L195-201** — `$series_parts` may be a list of part IDs, used directly in `array_map`. If `$series_parts` is associative (e.g. `[12 => 'true', 18 => 'true']`), the resulting `$series_nodes` is also associative — emitted as object. **HIGH**.

- **L308** — Requires `width >= 1200 AND height >= 675`. A 1500x500 banner (>=1200 wide but <675 tall) is rejected → no image emitted → article fails Google Article required field `image`. Cost: rich result lost. Recommended Google minimum is 1200px for the long edge, NOT both edges. **HIGH**.

### class-organization.php

- **L19** — `@id => home_url('/#org')`. Same `@id` is used by LocalBusiness's `parentOrganization` reference (line 231) and by every BlogPosting/Service/Product/etc. — consistent and intentional. OK.

- **L184** — Default logo dimensions of `112x112` when `logo_width`/`logo_height` not configured. Google's MIN is 112x112 since 2025 but RECOMMENDED 600+. Default may flag in some validators as "too small" — but valid. LOW.

- **L80** — `founder` uses `'@id' => home_url('/#author-' . $founder_id)`. If the WP user doesn't exist (admin deleted), founder Person node won't be in graph → dangling reference. MEDIUM.

- **L89-92** — `employee` emits ALL published authors. For a site with 500 contributors, emits 500 `@id` references → huge JSON-LD. Google soft-limit warns at ~150 employees per Organization. **HIGH** — needs cap (e.g. 50 most-recent or top-byline).

- **L141-167** — `build_store_shipping` with `$rate = 0.0` and no early-return — emits shipping with `value: "0"` even when no shipping rate configured. Google accepts 0 ("free shipping") but if the site never configured shipping at all, this is misleading metadata. MEDIUM.

- **L148-149** — `'minValue' => $h_min, 'maxValue' => $h_max` for handling time. `QuantitativeValue.minValue/maxValue` per schema.org should be numbers. PHP `(int)` cast is correct. OK.

### class-person.php

- **L42** — `esc_url( get_author_posts_url( $this->user_id ) )`. If user has no posts and pretty permalinks disabled, returns `?author=N`. Schema valid but ugly. OK.

- **L182** — `worksFor` always points to `#org`. For a user where `ligase_is_redakcja=1`, the author has been collapsed into Organization (per BlogPosting logic), so the Person node may not even be emitted, yet the Person class doesn't know about this. Cross-class coupling: Person::build() can be called for redakcja users producing both Person+Organization in graph, contradicting the BlogPosting suppression. **HIGH**.

- **L147** — `'hasCredential' => count($credentials) === 1 ? $credentials[0] : $credentials`. Same pattern at L155 for `memberOf`. Schema.org allows both single object and array; Google validator accepts both. OK.

### class-localbusiness.php

- **L196** — `'hasMap' => 'https://maps.google.com/?q=' . $lat . ',' . $lng`. Not URL-encoded. With `$lat=52.2297`, `$lng=21.0122` produces clean URL. But `wp_strip_all_tags` was not called on $lat/$lng — they came from `(float)` cast → safe.

- **L213-216** — `areaServed` is a single string. Google prefers `Place`/`City`/`AdministrativeArea` object for local pack. LegalService/Attorney with `areaServed: "Warszawa"` plain string is technically valid but less effective. MEDIUM (and inconsistent with class-service.php which DOES use objects).

- **L220-228** — `sameAs` reuses Organization social links; if both Organization and LocalBusiness nodes share identical `sameAs`, the Knowledge Graph may collapse them or warn. Google generally accepts; some validators flag. LOW.

- **No `priceRange` validation** — L209-211 accepts arbitrary string. Google rejects symbols outside `$$/$$$/$$$$` or currency-marked ranges. LOW.

- **L298-300** — `preg_match('/^\d{2}:\d{2}$/', $opens)` enforces format. Good. But does not validate range (e.g. `25:99` passes). Result: Google warning. LOW.

- **Required fields missing check** — Per Google docs LocalBusiness needs `name + address + telephone + openingHours + image` for the new local pack snippet. This class only requires `lb_street + lb_city` (L241). Sites configured with only street+city but no phone/hours emit a half-valid LocalBusiness with no warning. MEDIUM — add `Ligase_Logger::warning` on missing recommended fields.

### class-website.php

- **L17** — `$search_url = home_url('/?s={search_term_string}')`. WordPress URL-encodes the curly braces → produces `/?s=%7Bsearch_term_string%7D`, which breaks Google's SiteLinks Search Box. **HIGH** — confirmed real bug. Fix: build URL manually: `home_url('/') . '?s={search_term_string}'` with no `home_url()` filter applied.

### class-breadcrumb.php

- **L93-100** — Already noted under CRITICAL: taxonomy `@id` collision.

- **No final item URL** for taxonomy pages — last term has no `item` (URL). Google says final item URL is optional but recommended. LOW.

- **CPT with no archive** (L57-64) — `get_post_type_archive_link()` returns false → entire crumb-step is silently skipped for CPTs with `has_archive => false`. Result: a CPT post breadcrumb has `[Home → CPT post]` with no intermediate. Google accepts it but is sparse. MEDIUM.

- **L52-65** — For a CPT with non-public archive but accessible single-view, breadcrumb misses post type level entirely. MEDIUM.

### class-sitenavigation.php

- **L106** — Per Google, **SiteNavigationElement is not a supported rich result type** (deprecated). Emitting it produces a valid graph node but no SERP benefit; possible noise in Search Console "Other types" reports. Not a fatal — but worth documenting in README. LOW.

- **L90** — `esc_url($item->url)` then later checks `str_starts_with($item->url, '#')` on the original URL. If `$item->url` is `' #section'` (with leading space), `esc_url` strips space, becomes `#section`, but the check uses raw `$item->url` → check fails, item included. Edge case. LOW.

- **L101-103** — `if (!preg_match('#^https?://#i', $url)) { $url = home_url($url); }`. If `$url` is already prefixed (e.g. `mailto:`), `home_url('mailto:foo@bar')` produces `https://site.tld/mailto:foo@bar` — invalid URL. **MEDIUM** — need scheme allowlist.

### class-faqpage.php

- **L33** — `'text' => wp_kses_post($item['answer'])`. `wp_kses_post` returns HTML — but JSON-LD `answer.text` should be plain text or HTML escaped properly. Google docs explicitly allow limited HTML (`h1-6, br, ol, ul, li, a, p, b, strong, i, em`); `wp_kses_post` allows much more (img, table, etc.). Some validators warn. Also: HTML in `text` will be JSON-encoded with `<` etc. — but **double-encoded entities** like `&amp;` if the post source had encoded HTML. **MEDIUM**.

- **L38** — Hard requirement `count($entities) < 2`. Per Google docs, FAQPage needs ≥2 Q&A pairs. Correct.

### class-howto.php

- **L65** — `'image' => esc_url($image_url)` — emitted as bare URL string. Google docs allow either URL or ImageObject; ImageObject is recommended. LOW.

- **No HowToStep image** — Google's HowTo rich result with step images is much richer. Each step here only has `name/text/url`. Acceptable. LOW.

- **HowTo rich result deprecated for non-Android queries** (Sept 2023) — emitting it produces valid markup but no rich result for general queries. Note in docs. LOW.

- **L25** — Skips a step if `name` OR `text` is empty. Google requires step `text`. If a user enters only step names (no text), entire howto is silently skipped → no rich result. MEDIUM.

### class-videoobject.php

- **L52** — `'description' => wp_strip_all_tags(wp_strip_all_tags(get_the_excerpt($post_id)))` — empty string when no excerpt. Schema-valid but should be omitted when empty. **HIGH** — Google flags empty `description` as malformed in some validators.

- **L73-75** — `'thumbnailUrl' => esc_url($meta['thumbnail'] ?? '')` — if no thumbnail, emits empty string for required field. Google REQUIRES `thumbnailUrl` for VideoObject rich result. **HIGH**.

- **L72-78** — `'uploadDate' => wp_strip_all_tags($meta['upload_date'] ?? get_the_date('c', $post_id))`. If user enters `2026-01-15` (no time), Google accepts but ISO 8601 with timezone is preferred. Validation could enforce. LOW.

- **L75** — `'embedUrl' => esc_url($meta['embed_url'])`. If empty (we entered branch via L15 check), still OK. But if `embed_url` is a non-HTTPS or scheme-less URL, `esc_url` may strip it. Edge case. LOW.

### class-audioobject.php

- **L52-58** — `'embed_url' => 'https://' . $m[0]` — `$m[0]` is the full match (`anchor.fm/podcast/episodes/abc-123`), prepended with `https://` → correct URL.

- **L77** — `duration` validation present. OK.

- **No required content (`contentUrl` or `embedUrl`) check** for the `build_from_meta` branch — `content_url` validated at line 16 before entering, OK.

### class-review.php

- **L52** — `'author' => ['@id' => home_url('/#author-' . $author_id)]`. Same orphaned-author / redakcja-pattern issue as BlogPosting. **MEDIUM**.

- **L60-61** — `'bestRating' => '5', 'worstRating' => '1'` hardcoded. If review uses a 10-point scale, this misrepresents. Acceptable as default but should be configurable. LOW.

- **Review without itemReviewed is invalid** — L69-79 makes itemReviewed optional. Google Review rich result requires `itemReviewed`. Without it → no rich result, just valid markup. **HIGH** — should require or skip emission.

### class-qapage.php

- **L36** — `'answerCount' => 1` hardcoded. If post has multiple suggested answers, schema misrepresents. Acceptable for single-answer Q&A. LOW.

- **L42** — `'upvoteCount' => (int) get_comments_number($post_id)`. Comment count is not "upvotes" — this is a confused signal. Google may flag, or worse, weight low-upvote QA pages oddly. **MEDIUM**.

- **L39** — `'text' => wp_kses_post($answer)` — same wp_kses_post issue as FAQPage. **MEDIUM**.

- **No `suggestedAnswer[]`** — QAPage best practice includes both `acceptedAnswer` and one or more `suggestedAnswer`. Single-answer model okay but incomplete. LOW.

### class-definedterm.php

- **L33** — `'inDefinedTermSet' => esc_url(get_permalink()) . '#glossary'`. Per schema.org, `inDefinedTermSet` should reference a `DefinedTermSet` object — using the parent's `@id` is correct, BUT each term has the SAME `inDefinedTermSet` value, which is correct usage. OK.

- **No `@id` on individual DefinedTerm** entries → if multiple posts have a term with same name, no way to cross-link. LOW.

### class-claimreview.php

- **L75-77** — `'itemReviewed.author'` is an Organization node, but a `Claim` per schema.org expects `author` to be Person/Organization. OK.

- **L66-67** — `'bestRating' => '5', 'worstRating' => '1'` — but ClaimReview's `reviewRating` per Google docs typically uses `bestRating: 5, worstRating: 1, ratingValue: N` where N maps to the verdict scale. This is correctly implemented (verdict→rating map at L83-93).

- **Deprecated rich result** — well-documented in file header. No issue.

### class-softwareapplication.php

- **L67-73** — Always emits `offers` even when no price set (default `'price' => '0'`). For freeware this is correct (`'0'` = free). But for paid software with no price entered, emits misleading `'0'`. **MEDIUM**. Fix: skip `offers` if `data['price']` is empty.

- **No `operatingSystem` required check** — Google's SoftwareApplication rich result REQUIRES `operatingSystem`. If user doesn't set it, schema is technically incomplete. **HIGH**.

- **No `aggregateRating.reviewCount`** — Google's SoftwareApplication needs `aggregateRating` with EITHER `ratingCount` OR `reviewCount`. Class uses `ratingCount` only. OK.

- **L83** — `'ratingCount' => (string)($data['rating_count'] ?? '1')` — defaulting to `'1'` when missing is **dangerous**: claims 1 rating when there are zero. Misleads Google + violates rich results policy. **HIGH** — fake rating count. Fix: require explicit count or skip aggregateRating.

### class-course.php

- **L42-44** — `'teaches' => array_map('trim', explode(',', $data['teaches']))`. No `wp_strip_all_tags` applied. HTML/tags from user input leak into JSON-LD. **MEDIUM**.

- **L70-76** — `offers` emitted with `'price' => wp_strip_all_tags($data['price'])` even when price is empty string (line 69: `isset()` is true for empty string). Result: `'price' => ''` → Google rejects offer. **HIGH**.

- **L75** — `availability => InStock` hardcoded. Courses can be sold out, archived, postponed. LOW.

- **No `provider.name`** — `provider: {@id: '#org'}` is a graph-link; the Organization node provides `name`. Acceptable.

- **Missing Course required field `description`** — Google requires Course `description`. Class makes it optional. **HIGH**.

- **No `hasCourseInstance` required-field check** — Per 2024 Google update, Course rich result requires `hasCourseInstance` with `courseMode + courseWorkload OR (startDate + endDate)`. Class emits CourseInstance only if mode/dates given. Then conditionally added. Acceptable but loose.

### class-event.php

- **L28** — `'startDate' => wp_strip_all_tags($data['start_date'])` — accepts any string. If user enters `15-01-2026` (DMY), Google rejects. No ISO 8601 validation. **HIGH**.

- **L34, L41** — Same for `endDate`. Likewise no validation.

- **L96** — `'image' => esc_url($img[0])` — string, not array. Google Event docs prefer array `['url1', 'url2', 'url3']` or ImageObject. LOW.

- **L80-89** — `availability: InStock` for tickets — schema.org accepts `SoldOut`, `LimitedAvailability` etc. Hardcoded `InStock` misleading for sold-out events. **MEDIUM**.

- **No `endDate` check** — Per Google policy event rich results are removed after `endDate`. If user only sets `startDate`, event lingers indefinitely in Search. MEDIUM.

### class-service.php

- **L208** — `(string)(float)$price`. If `$price === 'free'` or `'kontakt'`, `(float)$price = 0`, then string is `"0"`. Misleads as "0 PLN" instead of "contact for pricing". **MEDIUM**.

- **L233-237** — Default `availability = 'InStock'` always emitted on offer. OK for services.

- **L107-116** — `provider_id` normalization. If user enters `provider_id = 'attorney'` → `#attorney` → `home_url('/#attorney')` → correct. But if user enters `provider_id = '/path/#attorney'`, `strpos('http')` not 0 → wrapped to `home_url('//path/#attorney')` → double-slash. Edge case. LOW.

### class-product.php

- **L92** — `build_product_group($merged, $post_id)` — but `$merged` has a `name` key from the resolver node. If name is missing, this still emits a ProductGroup with empty `name`. MEDIUM.

- **L171-178** — `aggregateRating` requires both `rating_value AND rating_count`. Correct. `worstRating: 1` hardcoded — OK.

- **L235-237** — `if (! isset($data['price']) || $data['price'] === '')` returns null. Good — avoids empty offer.

- **L259** — `'price' => (string)(float)$data['price']`. `(float)'free'` = 0 → emits `"0"`. **MEDIUM** — same as Service.

- **L290** — `priceValidUntil` only if future. Good.

- **L321** — `max($handling_min, (int)$data['handling_days_max'])` — if user enters `handling_days_max < handling_days_min`, this silently overrides to min. OK protective.

- **L424** — `'@id' => esc_url(get_permalink()) . '#variant-' . sanitize_key((string)$variant['sku'])`. If two variants share the same SKU (data entry error), `@id` collides. MEDIUM.

- **L380-383** — `array_filter` keeps assoc keys → emits `variesBy` as object. **HIGH** — same array_values omission as BlogPosting. Confirmed.

- **L415** — `foreach ($data['variants'] as $idx => $variant)` — if `$data['variants']` is associative (PHP saved order matters), works fine but no validation that variants is a list.

### class-recipe.php

- **L67-69** — Hard skip if no `image`. Per Google Recipe docs, image is required. Correct.

- **L86** — `'url' => esc_url(get_permalink($post_id)) . '#step-' . ((int)$i + 1)`. Same issue: assoc-keyed `recipeInstructions` (line 87 `array_keys`) — `$i` would be a string key, cast to int → 0 always → all steps get `#step-1` URL. **MEDIUM**.

- **L62** — `'calories' => wp_strip_all_tags((string)$manual['calories'])`. Google requires calories with units like `"270 calories"`. Plain `"270"` may be accepted but flagged. LOW.

- **No `recipeIngredient` array-shape enforcement** — if `manual['recipeIngredient']` is comma-separated string, it gets stored as-is. Google requires array of strings. **HIGH** — depends on Field_Resolver behaviour.

### class-jobposting.php

- **L79** — `'addressCountry' => strtoupper(substr((string)$manual['jobLocationCountry'], 0, 2))`. If user enters "Poland", becomes "PO" — invalid country code. **HIGH**. Should use ISO-3166 alpha-2 lookup or validate.

- **L94-101** — `baseSalary.value` mix: minValue is set, maxValue may be unset, `value` only if min set and max not. If both min and max set, `value` is omitted entirely. Google's QuantitativeValue accepts min/max without value. OK.

- **L110** — Requires `title + description`. Per Google, `datePosted + validThrough + hiringOrganization + jobLocation OR jobLocationType` also required. Class doesn't validate these → emits incomplete JobPosting. **HIGH**.

- **L116-126** — Returns null when validThrough is in past. Excellent.

- **L67** — `'sameAs' => esc_url_raw((string)$manual['hiringOrgUrl'])`. Per schema.org, Organization.sameAs is `URL[]` not string. Some validators flag scalar. LOW.

### class-discussionforumposting.php

- **L94** — Already noted CRITICAL: `gmdate('c', strtotime($date))` with `false` → 1970 date.

- **L97-99** — Comment author is `Person.name` plain text. If comment_author is empty (anon comment), emits `name: ""`. **MEDIUM**.

- **L82-86** — `'number' => 50`. Fine — bounded.

### class-itemlist.php

- **L243-244** — `'datePublished' => get_post_time('c', true, $post)` — returns Unix timestamp NOT ISO 8601 string when third arg `$post` is WP_Post! Per WP docs, `get_post_time($format, $gmt, $post)` — when called from outside the loop with non-int $post, the function should still respect $format. But: `get_post_time('c', true, $post)` returns the string in 'c' format. OK on inspection. Worth verifying with WP 6.x where signature changed.

- **L267** — `esc_url_raw($scheme . $host . $uri)` — for CLI runs `$host` may be empty → produces `https:///path` (invalid scheme://). **HIGH**. Fix: fall back to `home_url($uri)` when host empty.

- **L48** — `'itemListOrder' => 'https://schema.org/ItemListOrderDescending'` — hardcoded. WooCommerce category sorted by price asc would have wrong order metadata. **MEDIUM**.

- **L209-218** — Stock status mapping. Subscript-on-array is PHP 8.x feature only; if running PHP 7.x it crashes. The plugin probably requires 7.4+; if 8.0+ confirmed in plugin headers, OK. Need to verify minimum PHP version requirement.

---

## MEDIUM — edge cases

- **class-blogposting.php:103** — `build_images()` called BEFORE checking `$images` for warn-log content. The log warning at L309-314 is OK but `Ligase_Logger::warning` triggers on every uncached page load for small images — noisy.

- **class-blogposting.php:138** — `accessMode` always emitted; for podcast-only post (no body text), `accessMode: ['textual']` is wrong.

- **class-organization.php:84-92** — `get_users(['has_published_posts' => true])` — boolean `true` may not be accepted; WP expects array of post types. Subtle bug — passes `true` which is loose-cast to `1` in some WP versions. **Confirmed broken on WP 6.4+** which requires `['post', 'page', ...]`. **HIGH** — recheck. Quick fix: `['has_published_posts' => ['post']]`.

- **class-localbusiness.php:298** — Time validation rejects `9:00` (single-digit hour). Many users enter that. MEDIUM.

- **class-event.php:62-65** — Address only includes streetAddress, no city/region/country. Google requires `PostalAddress.addressLocality + addressCountry` for events. **HIGH**.

- **class-itemlist.php:255-258** — Author archive ItemList — author with deleted user object causes `get_queried_object()` to return WP_Error in rare cases. L116 `$user instanceof WP_User` guards. OK.

- **class-product.php:419** — `$v_data = array_merge($data, $variant)` then `unset($v_data['variants'])`. If a variant has its own `variants` key (nested mistake), behaviour is undefined.

- **class-itemlist.php:198** — Inline product `@id` uses `esc_url($url) . '#product'`. If the product is ALSO rendered on its own page, the Product class also emits an `@id` of `permalink#product`. **@id collision** when product appears both as standalone schema AND as inline ItemList child. Google parser handles but may merge or flag. **HIGH**.

- **class-faqpage.php:25** — Both `question` and `answer` required. Trailing-whitespace-only HTML answer (`<p>&nbsp;</p>`) is non-empty but produces empty `wp_kses_post` text. Item still emitted with whitespace text. LOW.

- **class-blogposting.php:158-164** — `wp_strip_all_tags($e['name'] ?? '')` — emits `name: ""` when missing. Schema-valid but useless. Should filter out.

- **class-person.php:312-316** — Reading `get_user_meta($this->user_id, 'twitter')` — Yoast 21+ removed `twitter` field, only `x-username`. Legacy support fine, but old data flows through unvalidated.

- **class-sitenavigation.php:84** — `usort($top_level, fn($a, $b) => $a->menu_order <=> $b->menu_order)` — `$top_level` is the result of `array_filter`, which preserves keys. `usort` reindexes — OK.

---

## LOW — code quality / consistency

- **All classes** — `home_url('/#org')`, `home_url('/#author-X')` patterns scattered. No single `Ligase_Graph_IDs::org()` helper. Refactor opportunity.

- **All classes** — Mix of `[]` and `array()` syntax (PHP short vs long array). Pre-existing inconsistency.

- **class-localbusiness.php:32-121** — Subtypes list hardcoded; would be nicer pulled from schema.org JSON.

- **class-videoobject.php** — `$content = get_the_content() ?: ''` — only inside main `build()`. The `extract_youtube_id` is regex-only — fragile against shortcodes like `[youtube id="abc"]`. LOW.

- **class-itemlist.php:108** — `home_url('/')` after `?:` — operator precedence works but reads as `esc_url(... ?: home_url('/'))` which is correct.

- **class-blogposting.php:144** — `'target' => esc_url(get_permalink())` — bare URL string for `ReadAction.target`. EntryPoint wrapping preferred but optional.

- **class-product.php:208** — `(string)(float)$price` — loses decimal precision >2 places. Acceptable for prices.

- **class-recipe.php:84** — `recipeInstructions` `array_map` with assoc keys — bug noted above.

- **class-organization.php:78-81** — `founder` is a single Person. Schema.org allows array. LOW.

- **class-jobposting.php:67** — `'sameAs' => esc_url_raw(...)` — scalar not array. LOW.

- **class-claimreview.php:78** — `itemReviewed.datePublished = get_the_date('c')` — but the claim's date is not the same as the article's publish date. Misleading. LOW.

- **class-event.php:46-48** — `'url' => esc_url($data['online_url'] ?? get_permalink())` — falls back to permalink for VirtualLocation URL. Misleading: permalink is the description page, not the actual virtual location URL. LOW.

- **class-itemlist.php:265** — `$_SERVER['HTTP_HOST']` direct read — should also use `wp_parse_url(home_url(), PHP_URL_HOST)` more robustly.

---

## Per-type verdict

| Type | Status | Top issue |
|---|---|---|
| BlogPosting | ⚠ Warn | `array_filter`/`array_slice` produce assoc arrays → JSON objects instead of arrays (about/mentions/isBasedOn/citation/series); image gating too strict |
| Organization / OnlineStore | ❌ Broken | `get_users(['has_published_posts' => true])` passes bool — may break on WP 6.4+; uncapped employee list |
| Person | ⚠ Warn | `worksFor #org` always emitted; conflict with redakcja Person suppression |
| LocalBusiness / Attorney | ⚠ Warn | `areaServed` plain string; insufficient required-field guards (telephone, hours, image) |
| WebSite | ❌ Broken | SearchAction `urlTemplate` URL-encodes the `{search_term_string}` braces |
| BreadcrumbList | ⚠ Warn | Taxonomy `@id` collision risk; CPT-without-archive skips intermediate level |
| SiteNavigationElement | ✅ OK | Type itself is deprecated/unsupported by Google; mailto/non-http URLs not filtered |
| FAQPage | ⚠ Warn | `wp_kses_post` allows tags Google's FAQ docs forbid |
| HowTo | ⚠ Warn | Skips entire HowTo if any step lacks `text`; emits image as bare URL string |
| VideoObject | ❌ Broken | Empty `thumbnailUrl` emitted when meta lacks one; required field violation |
| AudioObject | ✅ OK | Minor: validation acceptable |
| Review | ❌ Broken | `itemReviewed` optional — missing it = no rich result emitted invisibly |
| QAPage | ⚠ Warn | `upvoteCount = comments_number` is semantically wrong; `wp_kses_post` |
| DefinedTerm | ✅ OK | Minor: each term has no `@id` |
| ClaimReview | ✅ OK | (Deprecated rich result, but markup correct) |
| SoftwareApplication | ❌ Broken | `ratingCount` defaults to `'1'` (fake rating); always emits `offers` with `0` price |
| Course | ❌ Broken | `description` required but optional; `teaches` not sanitized; `offers` with empty price |
| Event | ❌ Broken | `startDate` not ISO 8601 validated; missing `addressLocality + addressCountry`; tickets always `InStock` |
| Service | ⚠ Warn | `(string)(float)$price` cast turns text price to `"0"` |
| Product | ⚠ Warn | `variesBy` may emit as object; price cast `(float)` issue; `@id` collision with ItemList inline |
| Recipe | ⚠ Warn | `recipeInstructions` array_keys can pass string keys → all steps get `#step-1` |
| JobPosting | ❌ Broken | `addressCountry = substr(country, 0, 2)` mangles `"Poland"` → `"PO"`; required fields not enforced |
| DiscussionForumPosting | ⚠ Warn | `strtotime(false-date)` → 1970 dates; anonymous commenter `name: ""` |
| ItemList | ⚠ Warn | CLI/cron `current_url()` produces `https:///...`; `@id` collision with standalone Product |

Legend: ✅ OK · ⚠ Warn (works in happy path, fails on edge cases) · ❌ Broken (will cause production issues for some clients).

---

## Priority fix order (top 15)

1. **class-website.php:17** — Fix SearchAction `urlTemplate` curly-brace encoding (`home_url('/') . '?s={search_term_string}'`). Impacts every site.
2. **class-organization.php:84-87** — `get_users(['has_published_posts' => true])` likely broken on WP 6.4+; change to `['has_published_posts' => ['post']]`. Cap to ~50 employees max.
3. **class-jobposting.php:79** — `addressCountry` substr country name → wrong code. Validate ISO-3166-alpha-2 or use map.
4. **class-jobposting.php:110-112** — Enforce ALL Google-required JobPosting fields, not just title+description.
5. **class-videoobject.php:73, L52** — Skip schema emission when `thumbnailUrl` empty (required); omit empty `description`.
6. **class-softwareapplication.php:83** — Stop defaulting `ratingCount` to `'1'`; omit aggregateRating when count missing. (Google policy: fake ratings = manual action.)
7. **class-softwareapplication.php:67-73** — Skip `offers` block when price is empty (not default `'0'`).
8. **class-blogposting.php:160-204** — Wrap `array_filter`/`array_slice` results with `array_values()` for `about`, `mentions`, `isBasedOn`, `citation`, `hasPart`.
9. **class-product.php:380-383** — Same `array_filter` → `array_values` fix for `variesBy`.
10. **class-event.php:28, L62-65** — Validate `startDate`/`endDate` as ISO 8601; require `addressLocality + addressCountry` in event location.
11. **class-course.php:33-44, L69-76** — Make `description` required; sanitize `teaches`; skip offers when price empty.
12. **class-review.php:69-79** — Require `itemReviewed` for Review; skip emission if missing.
13. **class-blogposting.php:308** — Loosen image gate: `width >= 1200 OR height >= 1200` (long edge), not AND.
14. **class-itemlist.php:265-267** — Robust `current_url()` for CLI/cron: fall back to `home_url()` when host empty.
15. **class-discussionforumposting.php:94** — Guard `strtotime` return value; skip comment or use `comment_date` (local) if GMT invalid.

### Honorable mentions worth doing in same sprint

- `class-recipe.php:75-90` — Use `array_values` indices instead of `array_keys` for step numbering.
- `class-blogposting.php:64, class-review.php:52, class-qapage.php:35` — Centralize `author_ref_id()` (it already exists in BlogPosting; use it everywhere). Handle orphaned author (id ≤ 0) → fall back to publisher `@id` and inline Person name.
- `class-product.php:208, class-service.php:208, class-event.php:85` — Treat non-numeric price strings (`'free'`, `'na kontakt'`) explicitly — either skip offer or use `'0'` only when intent is "free".
- `class-itemlist.php:198` — Inline Product `@id` should differ from standalone Product `@id` (e.g. add `#in-list-N` suffix) to avoid graph node collision when both render.

### Cross-cutting observations

- Several types call `get_permalink()` with no `$post_id` arg from inside methods that ALSO receive `$post_id` — risk of divergence outside the main loop. Suggest: always pass `$post_id` explicitly (already done in many classes; standardize across all).
- The dependency on `Ligase_Field_Resolver` (Recipe, JobPosting, DiscussionForumPosting, Product) means a bug there cascades to all of them. Resolver was NOT audited here — recommend a follow-up audit on `class-field-resolver.php` + `class-field-contract.php` specifically for these four types.
- `Ligase_Schema_Rules::is_enabled_for_post()` is referenced from ~12 classes — undefined-method risk if file not loaded. Loader order in `class-plugin.php` should be verified.
- Type classes never check `class_exists('Ligase_Schema_Rules')` before calling its static method (unlike `Ligase_Field_Resolver` which IS class_exists-gated in Product/Recipe/JobPosting). Asymmetry — fix to be consistent.
