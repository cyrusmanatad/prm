# M2 first-slice evidence

Local implementation evidence (2026-10-18): [structured results](local-verification.json), [desktop pipeline](pipeline-desktop.png), and [mobile pipeline](pipeline-mobile.png). Captures use synthetic records and the existing foundation palette/fonts. They show private lead detail, a seven-stage board, next action, activity logging, and history; they are not onboarding or automated outreach.

Four Chromium pipeline journeys passed on desktop/mobile. They cover every source stage, persisted reloads, two tabs editing the same version, preservation of the rejected draft, blocked retry until explicit reload, future snooze/due filtering, manual activity, deletion, viewer mode, and revocation. Both pipeline axe scans reported zero violations; body overflow checks pass. Source inspection and screenshot review do not establish screen-reader, actual browser-zoom, or native Safari acceptance; those reviews remain pending by D19.

23 identity, 12 real PostgreSQL integration, 15 acceptance, and 16 Vue tests passed. Runtime RLS/role tests include foreign-ID routes, adversarial partial props, actor/organization forgery, parent constraints, forbidden activity rewrite/delete, exact money totals, pagination, rollback, viewer denial, and revocation. The new migration passed rollback/reapply in a separate synthetic database with runtime grants and all pipeline integration tests; that database was removed. Type checks, build, Pint, standalone DDL, and current-source secret scan passed. The standalone DDL result is not runtime isolation evidence.

The first browser run caught an unsaved-draft loss on an error redirect. The page now preserves state, resets forms only when selected record/workspace changes, freezes the loaded version, and blocks writes until explicit reload after conflict. A later filter assertion was synchronized with completion of the preceding Inertia navigation. The final four pipeline journeys passed.

Full remote Chromium/WebKit and clean-setup checks are pending. Full M2 acceptance is not claimed: reusable contacts, dedicated reminders/completion, and manual outreach templates remain. See [M2 status](../../m2-status.md).
