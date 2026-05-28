# Ligase 2.0.0 — Audyt całościowy

**Data:** 2026-05-28
**Repo:** https://github.com/MarcinZmuda/Ligase-Schema-Markup
**Wersja:** 2.0.0
**Stan ogólny:** ❌ **NIE GOTOWA do publikacji na wordpress.org ani do produkcji u klientów**

Cztery niezależne audyty częściowe:
- [AUDIT_SCHEMA_TYPES.md](AUDIT_SCHEMA_TYPES.md) — 19 klas typów
- [AUDIT_PIPELINE_SCORE.md](AUDIT_PIPELINE_SCORE.md) — pipeline encji + score
- [AUDIT_AUDITOR_SUPPRESSOR.md](AUDIT_AUDITOR_SUPPRESSOR.md) — auditor + suppressor + importer
- [AUDIT_CORE_SECURITY.md](AUDIT_CORE_SECURITY.md) — core + WP security

---

## 🔥 BLOCKERY — naprawić zanim cokolwiek

### 1. Stored XSS w JSON-LD
`class-output.php:50-69` — `wp_json_encode` nie escapuje literału `</script>` w wartościach JSON. Dowolna treść FAQ/HowTo/excerpt/org name/bio zawierająca `</script>` wybija się z `<script type="application/ld+json">` i jest **cache'owana w transiencie 12h** → serwowana każdemu odwiedzającemu.

Fix: jednolinijkowy `str_replace('</', '<\/', $json)` po `wp_json_encode`. Też w `class-ajax.php:710-770` (auto-repair).

### 2. Suppressor nie suppressuje
Nazwy filtrów Yoast/Rank Math/AIOSEO/SEOPress/TSF są nieaktualne — żaden nie zostanie wyłączony. **Każda strona z Yoast + Ligase = podwójne schema.** Slim SEO ma pustą tablicę filtrów.

Skutek: identyczna BlogPosting × 2, identyczna Organization × 2, identyczna Person × 2 — Google traktuje to jako spam structured data.

### 3. Testy nie kompilują się
- `NERTest.php` woła `extract($content)` — produkcja ma `extract_from_post(int $post_id)`
- `ScoreTest.php` woła `calculate_site_score()/calculate_post_score()/calculate_author_score()` — produkcja ma `calculate()/calculate_for_post()/calculate_for_author()`
- `AuditorTest.php` woła `audit()` i `detect_plugins()` — nie istnieją

CI/local: każdy test fatals on load. **Efektywne pokrycie testami = 0%** dla trzech kluczowych podsystemów.

### 4. `Ligase_Auditor::intercept()` to dead code
Cały tryb pasywny (scan/supplement/replace przy ładowaniu strony) nigdzie nie podłączony. Grep `->intercept(` = 0 wyników poza klasą. Feature reklamowany w README nie działa.

### 5. "Supplement" w UI faktycznie odpala "Replace"
AJAX endpoint czyta `$mode` ale ignoruje go — zawsze woła `apply_replacement()`. Próg score też dekoracyjny. **Użytkownik wybiera tryb bezpieczny, dostaje destrukcyjny.**

### 6. Pipeline encji to nie 4 poziomy, tylko 2 + dwa nieużywane
`Ligase_Entity_Pipeline` nigdy nie woła `Ligase_NER_API`. Wyniki LLM (`_ligase_ner_api_results`) są zapisywane przez cron, ale **pipeline ich nie czyta**. Reklamowana "Level 3 NER ~20ms" pasuje tylko do regex extractora.

### 7. Score odłączony od pipeline'u
0–100 nie waliduje wygenerowanego JSON-LD, nie czyta `_ligase_wikidata_suggestions`, `_ligase_ner_api_results`, `_ligase_about_entities`, `_ligase_mentions`. **Domyślny WP dostaje ~20 pkt za nic** (inLanguage/publisher/articleSection/author zawsze niepuste). 95/100 osiągalne bez żadnej pracy AI.

### 8. AudioObject — podwójny prefix anchor.fm
`class-audioobject.php:56-57` — produkuje URLe `https://anchor.fm/anchor.fm/show/episodes/...` (404). Bug jednolinijkowy.

---

## ⚠️ POWAŻNE — wpływ na SEO klientów

### Cross-cutting: `esc_html()` psuje JSON-LD
Każda z 19 klas typu przepuszcza tekst przez `esc_html()` **przed** `wp_json_encode`. Polskie tytuły:
- Input: `Czym jest "lean SEO" & co dalej?`
- W JSON-LD: `"headline": "Czym jest &quot;lean SEO&quot; &amp; co dalej?"`

`wp_json_encode` sam escapuje wszystko co trzeba. Tutaj wystarczy raw UTF-8 albo `wp_strip_all_tags()`. Fix: usunąć `esc_html()` przed encodem we wszystkich `class-*.php` w `includes/types/`.

### Polski multibyte (`wordCount`)
`class-blogposting.php:63` — `str_word_count()` jest byte-level. Polskie słowa z ogonkami są niedoszacowane.

Fix: `preg_match_all('/\b[\p{L}\p{N}_]+\b/u', $text, $m); $words = count($m[0]);`

### Wikidata: polish-only, false matching
- Hardkodowany `language: 'pl'` — encje typu "Cloudflare", "Stripe" zwracają 0 wyników i są cache'owane na 4 tygodnie jako "no match".
- Auto-apply przy `count($matches) === 1` — `wbsearchentities` często zwraca 1 wynik dla ambiguous terms → wrong-entity linking.
- UA string niezgodny z [WMF policy](https://meta.wikimedia.org/wiki/User-Agent_policy).

### NER regex polish-inflection-blind
"Jan Kowalski / Jana Kowalskiego / Janowi Kowalskiemu" = 3 oddzielne encje. Polski demonstrativ "to" wpada w angielski locative regex → false-positive miejsca na każdym zdaniu zaczynającym się "To...".

### Replace mode bez undo
`_ligase_replaced_schema` zapisywane jako backup, **nigdy nie czytane**. `_ligase_needs_own_schema` ustawiane przez audytora, nigdy nie konsumowane.

### Skorer audytora niesprawiedliwy
Event/Product/LocalBusiness/Recipe dostają 0 pkt (brak `headline` + `datePublished`). Próg 50 → **The Events Calendar Event zostanie auto-zastąpiony przez BlogPosting**. To psucie SEO klienta.

### Importer broken na danych produkcyjnych
- Yoast `company_logo` od v14+ to attachment ID, nie URL → import = pusty logo
- Rank Math `knowledgegraph_logo` od v1.0.50 to attachment ID
- AIOSEO 3.x — serialized PHP, nie JSON; AIOSEO 4.5+ — inny path do social URLs

### Brakujące pola required-by-Google
| Typ | Brakuje | Skutek |
|---|---|---|
| HowTo | `image` | Niewidoczne jako rich result |
| SoftwareApplication | `image`, `publisher` | Niewidoczne jako rich result |
| FAQPage | `@id`, `inLanguage`, `isPartOf`, `mainEntityOfPage` | Graf orphaned, słabsze AI citation |
| Event | `location` (gdy attendanceMode = Offline a brak `venue_name`) | Niewidoczne jako rich result + warning w Search Console |
| Review | linkage do `#website`/`#org` | Słabsze AI citation |
| DefinedTerm | `sameAs`/Wikidata | Marnowana okazja AEO/GEO |

### VideoObject `maxresdefault.jpg`
404 dla ~30% YouTube. Fallback: `hqdefault.jpg`.

### SiteNavigationElement self-wraps
Outer `SiteNavigationElement.hasPart: SiteNavigationElement[]`. Powinien być `ItemList`+`ListItem` albo flat.

### `wp_verify_nonce` bez `wp_unslash`
`class-admin.php:297,411` — magic quotes mogą zepsuć weryfikację na niektórych hostach.

### Logger w webroot
`wp-content/uploads/ligase-logs/` — `.htaccess`-only protection. Na Nginx/LiteSpeed logi są publicznie dostępne. Plus może wyciekać PII (URL-e, treść).

### GSC credentials AES-CBC bez auth tag
Key = `wp_salt('auth')` — malleable, brak GCM. Inconsistent z NER API key który leży **plaintext** w options. Albo szyfruj wszystko, albo nic.

### Brak rate-limit na bulk NER
`ligase_ner_run_bulk` — admin może spalić cały budżet API jednym kliknięciem.

### Settings `sanitize()` zaczyna od defaults
Zapis sub-form czyści inne pola. Bug logiczny, traci ustawienia.

### Front-end block `render_callback` zapisuje post meta
`class-plugin.php:145-165` — block render to nie miejsce na DB write. Powoduje invalidation cache i może race-condition.

---

## 📋 WP.ORG — odrzucenia gwarantowane

Aktualnie wtyczka byłaby odrzucona z review pod kątem:

1. **Polish-only admin labels** — menu, błędy, komunikaty. WP requires English source.
2. **Brak Privacy section w `readme.txt`** — wtyczka komunikuje się z OpenAI/Anthropic/GSC/Wikidata/Dandelion (i inne) — wszystkie zewnętrzne usługi muszą być wymienione, z linkami do TOS/Privacy.
3. **Stored XSS** — punkt 9 guidelines (safe by default).
4. **Logger writeable directory in uploads** — punkt 18 (no creating files in webroot without need).
5. **`composer.json` referuje `blocks/*/index.js` — pliki nie są commitnięte.**

Szacowane 6–10h pracy do gotowości submission.

---

## ✅ MOCNE STRONY

- `WebSite SearchAction` — textbook-correct
- `Person.sameAs` — URL validation
- `BlogPosting` — `speakable`/`about`/`mentions`/`isBasedOn`/`temporalCoverage`/`hasPart` (świetna powierzchnia AI citation)
- `LocalBusiness` — strukturalne `openingHoursSpecification` + 60+ walidowanych podtypów
- `ClaimReview` — poprawne `alternateName` dla verdict
- `QAPage` — exactly-one-Question shape (zgodne z Google)
- Centralized `verify_request()` dla wszystkich 30+ AJAX actions
- `$wpdb->prepare` używane konsekwentnie wszędzie
- Cache keys zawierają `LIGASE_VERSION` (poprawna invalidacja na bumpie)
- Meta-box save: nonce + autosave-skip + cap check + whitelist schema-type
- Sanitize/import używają explicit whitelists

---

## 🎯 PLAN NAPRAWY (rekomendowana kolejność)

### Sprint 1 — Bezpieczeństwo (2-3h)
1. Fix XSS `</script>` w `class-output.php` + `class-ajax.php` (1-liner × 2)
2. Usunąć `esc_html()` przed `wp_json_encode` w 19 typach (sed-friendly)
3. `wp_unslash` przed `wp_verify_nonce` w `class-admin.php`
4. Logger: ścieżka poza webroot albo PHP-die guard

### Sprint 2 — Bugfixes critical (3-4h)
5. AudioObject anchor.fm prefix
6. VideoObject `hqdefault.jpg` fallback
7. Event location-when-Offline guard
8. HowTo/SoftwareApplication required `image`/`publisher`
9. FAQPage `@id` + graph linkage
10. `wordCount` multibyte (`preg_match_all` + `u` flag)
11. Settings `sanitize()` merge zamiast reset
12. Block render_callback → przenieść meta save do save_post hook

### Sprint 3 — Suppressor (2-3h)
13. Zaktualizować nazwy filtrów dla Yoast/Rank Math/AIOSEO/SEOPress/TSF/Slim SEO (aktualne w 2025-2026)
14. Test integracyjny: aktywuj Yoast + Ligase, sprawdź że schema renderuje się raz
15. Replace mode: real undo via `_ligase_replaced_schema` read path
16. Supplement mode: faktyczny merge bez nadpisywania @id
17. Scorer audytora: nie penalizować Event/Product/LocalBusiness za brak headline

### Sprint 4 — Pipeline + Score (4-6h)
18. Wpiąć `Ligase_NER_API` w `Ligase_Entity_Pipeline` jako Level 3
19. Score: czytać `_ligase_wikidata_suggestions`/`_ligase_ner_api_results`/`_ligase_about_entities`/`_ligase_mentions` jako warunki bonusu
20. Wikidata: lang fallback `pl → en`, próg auto-apply na `confidence > X` zamiast `count === 1`
21. NER regex: lemmatyzacja PL przed dedup
22. Bulk NER: rate-limit + progress counter (`update_option(progress)` w pętli)

### Sprint 5 — Testy (2-3h)
23. Naprawić sygnatury w 4 plikach `tests/unit/*.php`
24. Dodać integration test dla XSS-escape JSON-LD
25. Dodać integration test dla suppressora (Yoast active → Ligase inactive)

### Sprint 6 — WP.org submission (2-3h)
26. Wszystkie admin strings przez `__('...', 'ligase')` — usunąć polskie literalne
27. `languages/ligase-pl_PL.po` z tłumaczeniami
28. `readme.txt` Privacy section z listą external services
29. Commitnąć `blocks/*/index.js` albo dodać build do CI

**Łącznie: 15-22h pracy.** Po Sprint 1+2+3 wtyczka jest bezpieczna do produkcji u własnych klientów (Smarthost). Po Sprint 4+5 nadaje się do dystrybucji. Po Sprint 6 do wordpress.org.

---

## Lista plików do edycji (full)

| Plik | Sprint | Co |
|---|---|---|
| `includes/class-output.php` | 1 | XSS fix |
| `includes/class-ajax.php` | 1 | XSS fix w auto-repair |
| `includes/types/class-*.php` (×19) | 1 | usunąć `esc_html()` przed encode |
| `admin/class-admin.php` | 1 | `wp_unslash` przed nonce |
| `includes/class-logger.php` | 1 | path poza uploads + die guard |
| `includes/types/class-audioobject.php` | 2 | anchor.fm prefix |
| `includes/types/class-videoobject.php` | 2 | hqdefault fallback |
| `includes/types/class-event.php` | 2 | location guard |
| `includes/types/class-howto.php` | 2 | required image |
| `includes/types/class-softwareapplication.php` | 2 | required image+publisher |
| `includes/types/class-faqpage.php` | 2 | @id + graph linkage |
| `includes/types/class-blogposting.php` | 2 | wordCount multibyte |
| `admin/class-settings.php` | 2 | merge sanitize |
| `includes/class-plugin.php` | 2 | block render_callback |
| `includes/class-suppressor.php` | 3 | filter names update |
| `includes/class-auditor.php` | 3 | intercept wiring + scorer |
| `includes/class-importer.php` | 3 | attachment ID handling |
| `includes/entities/class-pipeline.php` | 4 | wpiąć NER API |
| `includes/class-score.php` | 4 | czytanie pipeline meta |
| `includes/entities/class-wikidata-lookup.php` | 4 | lang fallback + confidence |
| `includes/entities/class-extractor-ner.php` | 4 | PL lemmatization |
| `includes/class-ner-api.php` | 4 | rate-limit + progress |
| `tests/unit/*.php` (×4) | 5 | method signatures |
| Wszystkie admin views | 6 | i18n strings |
| `readme.txt` | 6 | Privacy section |
