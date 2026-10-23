# Brand avatar dropdown

The brand navigation places a user-initials avatar beside Invite partners. Its dropdown contains Switch workspace / account security and Sign out, with Lucide icons matching the partner menu. The footer duplicates are removed. The brand menu uses nonmodal keyboard navigation inside an Account navigation landmark.

Type checks and the production build pass. Four focused Chromium/WebKit desktop/mobile checks verify placement, icons, keyboard opening/Escape/focus restoration, workspace navigation, sign out, the existing invitation dialog and document/menu overflow. Open-menu axe scans report zero violations; their 2–3 incomplete findings per viewport are retained in [structured verification](verification.json), rather than claimed resolved. No backend behavior or schema changes are part of this follow-up.

Screenshots: [desktop](desktop.png), [mobile](mobile.png). Original brand reference parity and earlier milestone checks remain historical, scoped to their recorded source. Manual release reviews and remote CI were not run.
