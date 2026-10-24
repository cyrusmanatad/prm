# Pipeline section: implementation spec

Reference files (source of truth, in priority order):
1. `pipeline-reference.dc.html`: markup with every inline style. Treat its values as exact. `{{x}}` are data holes, `<sc-for>` is a loop, `<sc-if>` is a conditional, `onClick`/`onInput`/`onChange` are handlers. Ignore the `<x-dc>` / script wrapper; port the markup and logic into this repo's framework.
2. `pipeline-redesign.png` (screenshot of the design, if provided).
3. This file.

Match the reference exactly: spacing, radii, colors, font sizes, weights. Do not add features, libraries or visual flourishes. Use the repo's existing framework, styling approach and component conventions; if the repo has design tokens, map the values below onto them instead of hardcoding.

## Tokens
| Token | Value |
|---|---|
| Page bg | #F8F3EA |
| Card bg | #FFFFFF, border #E6DCCB, radius 20, padding 28 |
| Column bg | #FAF5EC, border #F0E7D6, radius 16, padding 14 |
| Text | #1F1A1D |
| Muted text | #5E555B |
| Input/select border | #D9CDB8 (radius 12, height 44) |
| Segmented track | #F3ECDF (radius 12, padding 4) |
| Primary | #8A1148 (focus ring 2px, offset 2px) |
| Primary tint | bg #F6E3EC, text #6E0C3A |
| Warning tint | bg #FBEBD0, text #6B4200, dot #B7791F |
| Stage dots | Lead #8FA3B8, Pitched #C9A24B, Negotiating #D9803B, Onboarded #5F9C84, Active #8A1148 |
| Font | Plus Jakarta Sans 400/500/600/700 (fallback system-ui) |

## Structure (top to bottom, gap 24)
1. **Header**: left: h2 "My pipeline" (24/700) + pill "Automation unavailable" (12/600, #F3ECDF) and subtitle "Open a lead to edit it, move its stage or start onboarding." (14, muted). Right (right-aligned): uppercase label "Pipeline value" (12/600, tracking .04em), total (30/700, tabular-nums), "N leads · excludes Lost" (13, muted).
2. **Toolbar** (wrap, gap 12/16):
   - Segmented control: All active / Repeat clients / Lost, each with a count badge. Active = white pill with shadow and burgundy badge. Inactive = transparent with #E6DCCB badge.
   - Right group: search input (icon at left, placeholder "Search brands", max-width 320), stage select ("All stages" + 5 stages, custom chevron), toggle chip "Due next actions" (`aria-pressed`; on = primary tint + check icon), and a ghost "Clear filters" button that renders only when any filter is active.
3. **Board**: horizontal grid, `grid-auto-flow: column; grid-auto-columns: minmax(212px, 1fr); gap 14`, scrolls horizontally on narrow screens. One column per stage (Lead, Pitched, Negotiating, Onboarded, Active). Column header = dot + name + count pill, with the stage total beneath in 12px muted. Lead card = `<button>`: brand (15/600), amount (13 muted, tabular-nums), optional tags ("Repeat client" primary tint, "Action due" warning tint). Hover: border #8A1148 + soft shadow. Empty column: dashed "No leads" box.
4. **Empty state** (when nothing matches): centered dashed panel, title + body + "Clear filters" primary button (only if filters are active). Copy: "No leads match these filters", "No lost leads", "No repeat clients yet".

## Behaviour
- Segment tabs, search (case-insensitive substring on brand), stage select and due toggle all filter live. There is no Apply button.
- Stage select shows only the chosen column.
- Header total and lead count always cover non-Lost leads, regardless of filters.
- Amount format: `USD 1,250.00`.

## Sample data (replace with real data source)
Cedar Studio 650 Lead; Aurora Goods 800 Pitched (due); Summit Outdoors 950 Negotiating (due); Harbor Coffee 1100 Onboarded; Bloom Skincare 1250 Active; Atlas Travel 1400 Active (repeat client). Lost: none.

## Accessibility
Real buttons only. `aria-pressed` on toggles and segments, `aria-label` on search/select/cards, decorative icons `aria-hidden`, visible focus ring, touch targets ≥ 44px (segments 36px inside the track).
