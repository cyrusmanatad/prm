# Project Overview

## Source verification

- **PDF:** `docs/Creator_Brand_Platform_Business_Plan.pdf` is readable. All nine page-content streams were decoded using existing Python standard-library facilities (ASCII85 and Flate decoding). Text, tables, roadmap, and schema excerpts were inspected. No PDF renderer is available; page appearance was not visually inspected. This extraction method is specific to this ReportLab document, not a general PDF parser.
- **HTML:** `mockup/index.html` is readable, approximately 2.1 MB. Its root manifest contains seven compressed HTML boards. Nested manifests and templates were decoded in memory to inspect authored markup, CSS, component logic, and asset metadata. Embedded scripts include the DC runtime, React, and ReactDOM; bundled WOFF2 fonts coexist with some external Google Fonts references. No source files were changed.
- **Running preview:** `http://localhost:5500` refused connections both inside and outside the sandbox. This establishes unavailability from this execution environment, not whether a server exists on the user's machine.
- **Browser:** no browser tool, Chromium/Chrome executable, or Python Playwright package was found. Rendered appearance, runtime console behavior, click execution, accessibility, and responsive behavior remain unverified. No dependencies were installed.
- `frontend/`, `backend/`, and `planning/` were empty at inspection. There is no application manifest, test suite, or Git metadata in this checkout.

## Business concept

**PDF pp.2–3:** a creator-first system of record for finding, pitching, onboarding, and retaining brand partners. Initial customers are influencers and small brands in the Philippines, with mobile-first and Taglish-friendly workflows. Agency and enterprise capabilities follow later.

The initial value is fewer lost follow-ups and less paperwork scattered across spreadsheets and chats. Later value comes from measurable sales attribution, commission accountability, and local payout workflows. The PDF's competitive positioning is a business hypothesis, not validated market research here.

## Users and workflows

- Creator: capture a brand/contact → pitch → negotiate → invite brand onboarding → collect brief, rights, billing, and shipping information → manage paperwork and payment status.
- Invited brand: open a scoped onboarding invitation → consent and submit information → respond to requested corrections. A broader brand account is a separate product capability.
- Brand program manager, later: recruit creators → onboard → distribute campaign kits → attribute sales → approve commissions → reconcile payouts → nurture partners.
- Agency and enterprise administrators, later: delegated portfolios, approvals, integrations, identity federation, and reporting.

## Commercial model and validation

**PDF pp.7–9:** creator freemium subscriptions, brand plans/modules, payout fees, marketplace referrals, and later agency/enterprise contracts. Prices and fees remain unapproved hypotheses. Validation gate: 20–30 paying or committed pilots, following interviews with 30–50 creators and 15–20 brands. Confirm whether this work has already happened before scheduling a full build.

## Approved technical direction

The team uses Laravel/PHP. The approved stack is Laravel with Vue 3, TypeScript, Inertia, Tailwind/shadcn-vue, PostgreSQL RLS, Redis-backed Laravel queues/scheduler, Docker Compose, and GitHub Actions. Plan one Laravel application with Vue assets built by Vite. Mockup React/runtime assets remain source evidence, not selected application code.

The OVH VPS is development-only and will be sized for the project; initial recommendation is 2 vCPU, 4 GB RAM, and approximately 80 GB SSD, subject to measurement. Private uploads will use Laravel's filesystem abstraction; AWS S3 or OVH Object Storage selection, region, and cost remain open. Production hosting remains a later decision.
