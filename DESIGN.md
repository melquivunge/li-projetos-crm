# Design: CRM LI Projetos

Surface mode: **Operate**. Direction pinned by the user's references (`design/referencias/`) and brand: an "Aisle"-style soft SaaS shell (rail + topbar, rounded cards with an icon chip), a dark theme in the spirit of the black/lime reference, and a split login with the studio's own render. seed: none (pinned).

## Themes (`<html data-theme>`, saved per user in `users.theme`)
| Token | brand (default) | light | dark |
|---|---|---|---|
| ground | #F3EEE5 | #F4F5F7 | #0D0D0C |
| surface | #FFFDF9 | #FFFFFF | #171715 |
| ink / muted | #1A1918 / #6B645C | #17181A / #62666D | #EFEBE4 / #A49D92 |
| accent | #866628 | #866628 | #D4B06A |
| rail | #1A1918, active pill #C5A059 | #FFFFFF, active pill #17181A | #121211, active pill #D4B06A |
| primary button | ink | ink | gold |

All tokens are CSS variables in `public/assets/app.css`, bridged to Bootstrap's `--bs-*` variables. Bootstrap's dark components follow `data-bs-theme`.

## Type
- UI: **Plus Jakarta Sans** (same as the website), self-hosted (`public/assets/fonts`), variable 200–800.
- Signature: **Cormorant Garamond** for the login title, the dashboard greeting and the login figures. It also keeps "Iata" from reading as "lata", since Jakarta's capital I is a bare stem.
- Numbers use tabular numerals; the serif uses lining numerals.

## Components
- **Rail:** 264px, sticky at lg+; a Bootstrap offcanvas below 992px.
  - Groups: Operação and Gestão (admin only).
  - Count pills: leads in "Novo" and unread notifications.
  - User card at the bottom, linking to /perfil.
- **Topbar:** search (focused with "/"), "Novo cliente" (xl+), bell dropdown (6 latest, mark all read), avatar menu (profile, theme switch, sign out).
- **Cards:** 16px radius, shadow only (no border).
- **Stat tiles:** label, value, hint, plus a 40px icon chip in accent-soft.
- **Icons:** one authored 24px, 1.75-stroke SVG set (`icon()` in `src/helpers.php`). No emoji, no icon font.
- **Controls:** 44px minimum height. Focus ring is 3px in the theme's focus colour.

## Constraints
- Strict CSP: no inline `style` or `script`. Behaviour lives in `public/assets/app.js`; layout helpers are classes.
- Contrast: AA on every theme. Stage badges have light and dark variants.
