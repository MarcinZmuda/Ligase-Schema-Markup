# Audit history

Records of the comprehensive audit pass that led from Ligase 2.0.0 → 2.3.0.

## Background

In May 2026 the plugin underwent a multi-agent audit covering schema correctness,
WordPress security, performance, and the AI-citation roadmap. Four specialist
agents produced independent findings on:

- `AUDIT_SCHEMA_TYPES.md` — every type class vs schema.org spec and Google
  rich-results requirements (May 2026).
- `AUDIT_PIPELINE_SCORE.md` — entity pipeline (Native / Structural / NER /
  Wikidata) and the AI Search Readiness Score.
- `AUDIT_AUDITOR_SUPPRESSOR.md` — Schema Auditor (scan / supplement / replace)
  and the Suppressor that detects competitor SEO plugins.
- `AUDIT_CORE_SECURITY.md` — bootstrap, AJAX, admin UI, cache, logger, GSC,
  health report, and WordPress security best practices.

`AUDIT_SUMMARY.md` is the cross-cutting synthesis (10 priority levels).

## Resolution

`FIXES_APPLIED.md` documents which findings were fixed in each release
(2.0.1, 2.0.2, 2.1.0, 2.2.0, 2.3.0) and which were intentionally deferred.
The plugin's `readme.txt` carries the user-facing changelog.

These files are kept in the repository as historical record — they reflect the
state of the code at audit time, not the current state. For the live spec see
the source code and `readme.txt`.
