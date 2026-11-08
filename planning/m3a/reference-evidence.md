# WeavDash HTML prototypes

- `brand-partner-hub.html` — brand workspace, recreated from Brand partner hub.png.
- `partner-my-leads.html` — partner workspace, recreated from Partner portal – My leads.png.
- `assets/dashboard.js` — shared UI demo interactions.

Open either HTML file directly in a browser, or serve this folder:

```bash
cd /home/cyrusadmin/weavdash_assets/weavdash
python3 -m http.server 5510
```

Then visit http://localhost:5500/brand-partner-hub.html or http://localhost:5500/partner-my-leads.html.

Styling uses Tailwind CSS v4, compiled into the included `assets/tailwind.css`. Both pages work offline with no CDN or browser compilation. Keep the `assets` directory alongside the HTML files.

Fonts are served locally from `assets/fonts/`: Manrope for body text and controls, and Bricolage Grotesque for headings and the wordmark, matching the source app. Font declarations are in `assets/fonts.css`; the included `assets/fonts/OFL.txt` contains their license.

Pages are styled with Tailwind utility classes directly in the HTML; `assets/styles.css` only holds the theme tokens (colors and fonts) taken from the mockup. After changing styles or adding Tailwind classes to HTML/JavaScript, rebuild the stylesheet:

```bash
npm ci
npm run build:css
```

Use `npm run watch:css` to rebuild automatically while developing. Node.js is only required for development; opening the finished pages does not require it.

Content is sample data from the mockup. As in the mockup, Autopilot switches toggle, next-best-action buttons clear their row, partner nudge buttons switch to "Sent", and "Approve & protect 60 days" marks a lead approved; all of this is in memory and resets on refresh. Other buttons and links are visual only. There is no backend or authentication.
