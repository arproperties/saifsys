# ARS Home Rentals — Mobile App Style Guide

Design system extracted from the "Book Luxury Stays" concept mockup.
Surface: guest-facing booking app (iOS + Android). Brand: AR Properties / ARS Home Rentals.

**Design intent:** deep navy as the brand ground, brushed gold as the single accent,
warm cream sheets carrying all interactive content. High-contrast serif for voice,
neutral sans for everything functional. Nothing is loud — luxury reads as restraint.

**Relationship to the existing web app:** the ARS staff web app
(`modules/ars/assets/ars_styles.css`) already uses gold `#b8860b` / `#d4af37` on cream
`#f7f5f0`. This guide keeps that gold family and adds the navy ground + softened
brand gold `#C6A15E` used in the mockup. Deep gold `#8A6808` is retained as the
accessible text-weight gold. Do not introduce a third accent hue.

---

## 1. Color

### 1.1 Navy — brand ground

| Token | Hex | Use |
|---|---|---|
| `navy.900` | `#0A1729` | Deepest corner of hero gradient, status bar scrim |
| `navy.800` | `#0E2038` | App background (dark surfaces), splash |
| `navy.700` | `#10233C` | **Primary navy** — hero base, bottom nav |
| `navy.600` | `#16304F` | Raised navy surface, hero mid-gradient |
| `navy.500` | `#1D3A5C` | Navy card / pressed state |
| `navy.400` | `#2B4E75` | Borders and dividers on navy |
| `navy.300` | `#5B7799` | Muted icon / secondary text on navy |

**Hero gradient:** `linear-gradient(145deg, #0A1729 0%, #10233C 45%, #1D3A5C 100%)`

### 1.2 Gold — the only accent

| Token | Hex | Use |
|---|---|---|
| `gold.50` | `#FBF3E4` | Tint wash behind gold icons |
| `gold.100` | `#F7E3BE` | Stepper buttons, chip icon circles |
| `gold.200` | `#EDD5A3` | Disabled gold fill |
| `gold.300` | `#DCBC7E` | Gradient highlight, pressed gold |
| `gold.400` | `#D0AA66` | Hover / lighter gold |
| `gold.500` | `#C6A15E` | **Brand gold** — primary button, headline accent, rules |
| `gold.600` | `#B8860B` | Legacy web-app gold; icons on cream |
| `gold.700` | `#8A6808` | **Gold text on light backgrounds** (AA-safe) |

### 1.3 Cream — content sheets

| Token | Hex | Use |
|---|---|---|
| `cream.0` | `#FFFFFF` | Chip cards, elevated tiles, modals |
| `cream.50` | `#FCF9F4` | **Sheet background** — the main scrolling surface |
| `cream.100` | `#F7F5F0` | Alt page background (matches web app `--ars-bg`) |
| `cream.200` | `#F6EEE1` | Inset panel ("Your stay" container) |
| `cream.300` | `#F1E5D2` | Input field fill (Check-in / Check-out tiles) |
| `cream.400` | `#E8E4DC` | **Border** (matches web app `--ars-border`) |
| `cream.500` | `#DCD4C6` | Strong divider |

### 1.4 Text

| Token | Hex | Use |
|---|---|---|
| `text.primary` | `#1A1A1A` | Headings and values on cream |
| `text.secondary` | `#4A4A4A` | Field labels |
| `text.muted` | `#6B7280` | Captions, helper text, "per night" |
| `text.disabled` | `#9CA3AF` | Placeholders |
| `text.onNavy` | `#FFFFFF` | Hero headline |
| `text.onNavyMuted` | `#C8D3E0` | Hero caption |
| `text.onGold` | `#1A1A1A` | Text on gold fills — **never white** |

### 1.5 Semantic (carried over from the web app — keep meanings identical)

| Token | Hex |
|---|---|
| `success` | `#059669` |
| `warning` | `#D97706` |
| `danger`  | `#DC2626` |
| `info`    | `#0284C7` |

**Booking status colors** (must match `--ars-status-*` in the web app so staff and
guest views agree):

| Status | Hex |
|---|---|
| Pending | `#D97706` |
| Confirmed | `#B8860B` |
| Checked in | `#059669` |
| Checked out | `#64748B` |
| Cancelled | `#DC2626` |
| No-show | `#9A3412` |

### 1.6 Contrast rules — read this before using gold

Gold `#C6A15E` on white is **2.4:1**. It fails AA for body text.

- ✅ Gold **fill** with `#1A1A1A` text → 6.1:1. This is the primary button.
- ✅ Gold **text on navy** `#10233C` → 6.4:1. This is "ARS Home Rentals".
- ✅ Gold `#8A6808` **text on cream** → 5.9:1. Use this for gold-colored small text.
- ❌ Never white text on a gold fill.
- ❌ Never `#C6A15E` text on cream/white below 24pt.
- Gold `#C6A15E` on cream is fine for **icons, rules, borders and 28pt+ display type**.

---

## 2. Typography

Two families. No third.

| Role | Family | Fallback stack |
|---|---|---|
| Display / voice | **Playfair Display** | `Georgia, 'Times New Roman', serif` |
| UI / body | **Plus Jakarta Sans** | `system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif` |

Plus Jakarta Sans is already bundled for the web app at
`modules/ars/assets/vendor/fonts/plus-jakarta-sans/` — reuse those files.

### 2.1 Type scale (pt / dp, based on a 390pt-wide viewport)

| Token | Family | Size | Line | Weight | Tracking | Example |
|---|---|---|---|---|---|---|
| `display.xl` | Serif | 56 | 60 | 700 | -0.02em | Marketing hero only |
| `display.lg` | Serif | 40 | 46 | 700 | -0.015em | Onboarding |
| `h1` | Serif | 32 | 38 | 700 | -0.01em | "Stay With Us" |
| `h2` | Serif | 24 | 30 | 600 | -0.01em | Screen titles |
| `h3` | Sans | 19 | 26 | 700 | 0 | "Your stay" |
| `subtitle` | Sans | 17 | 24 | 600 | 0 | "ARS Home Rentals" |
| `body.lg` | Sans | 17 | 26 | 500 | 0 | Field values, "Select" |
| `body` | Sans | 15 | 23 | 400 | 0 | Paragraphs |
| `label` | Sans | 13 | 18 | 600 | 0.01em | "Check-in" |
| `caption` | Sans | 13 | 18 | 400 | 0 | "Luxury furnished residences • AED" |
| `chip` | Sans | 12 | 16 | 600 | 0 | "Best Price Guarantee" |
| `button` | Sans | 17 | 22 | 700 | 0.01em | "Explore residences" |
| `overline` | Sans | 11 | 14 | 600 | 0.18em, UPPERCASE | Section eyebrows |
| `wordmark` | Serif | 12 | 16 | 400 | 0.38em, UPPERCASE | "P R O P E R T I E S" |

### 2.2 Rules

- Serif is for **nouns and moments** — screen titles, property names, prices on a
  detail page. Never for labels, buttons, tabs, form text or errors.
- Two-tone headline treatment (white line + gold line, as in "Book **Luxury** Stays")
  is reserved for hero and empty states. Max once per screen.
- Numbers in money, dates and counts use **tabular figures**
  (`font-variant-numeric: tabular-nums`) so ledgers and price rows don't jitter.
- Body text never goes below 13pt. Respect OS Dynamic Type up to 200%.

---

## 3. Spacing

4pt base grid.

| Token | Value |
|---|---|
| `space.1` | 4 |
| `space.2` | 8 |
| `space.3` | 12 |
| `space.4` | 16 |
| `space.5` | 20 |
| `space.6` | 24 |
| `space.8` | 32 |
| `space.10` | 40 |
| `space.12` | 48 |

**Layout constants**

- Screen horizontal padding: `20`
- Sheet inner padding: `20`
- Card padding: `16`
- Gap between stacked cards: `12`
- Gap between chips in a row: `10`
- Section spacing: `28`
- Bottom safe area + nav: `84`

---

## 4. Radius

| Token | Value | Use |
|---|---|---|
| `radius.sm` | 10 | Badges, small tags |
| `radius.md` | 14 | Input tiles, trust chips |
| `radius.lg` | 20 | Inset panels, listing cards |
| `radius.xl` | 28 | **Sheet top corners** over the hero |
| `radius.button` | 16 | Primary / secondary buttons |
| `radius.full` | 999 | Steppers, back button, context pills, avatars |

---

## 5. Elevation

Shadows are soft, warm and low. Never a hard drop shadow.

| Token | Value |
|---|---|
| `elevation.sm` | `0 1px 3px rgba(26,26,26,0.05), 0 1px 2px rgba(184,134,11,0.05)` |
| `elevation.md` | `0 4px 16px rgba(26,26,26,0.07)` |
| `elevation.lg` | `0 12px 32px rgba(26,26,26,0.12)` |
| `elevation.sheet` | `0 -8px 24px rgba(10,23,41,0.10)` (sheet rising over hero) |
| `elevation.gold` | `0 8px 20px rgba(198,161,94,0.35)` (primary button rest) |

Android: map to `elevation` 1 / 3 / 8 respectively and tint with `#1A1A1A`.

---

## 6. Motion

| Token | Duration | Curve |
|---|---|---|
| `motion.fast` | 150ms | `cubic-bezier(0.4, 0, 0.2, 1)` |
| `motion.base` | 200ms | `cubic-bezier(0.4, 0, 0.2, 1)` |
| `motion.slow` | 250ms | `cubic-bezier(0.4, 0, 0.2, 1)` |
| `motion.sheet` | 320ms | `cubic-bezier(0.32, 0.72, 0, 1)` (iOS sheet feel) |

Press feedback: scale to `0.98` over `motion.fast`. No bounce, no spring overshoot.
Honour "Reduce Motion" — drop to opacity-only transitions.

---

## 7. Components

### 7.1 Hero
- Full-bleed photo, height ≈ 42% of screen.
- Bottom scrim: `linear-gradient(180deg, rgba(10,23,41,0) 35%, rgba(10,23,41,0.78) 100%)`.
  The scrim is mandatory — the serif headline must never sit on raw photo.
- Headline `h1` white, subtitle `subtitle` in `gold.500`, caption `caption` in `text.onNavyMuted`.

### 7.2 Circular back button
- 44×44, `radius.full`, fill `#FFFFFF` at 92% opacity, icon `#10233C` 20pt, `elevation.sm`.
- 44pt is the minimum tap target everywhere in this app.

### 7.3 Context pill (e.g. "Dubai stays")
- Height 34, `radius.full`, fill `rgba(10,23,41,0.55)`, 1px border `rgba(198,161,94,0.55)`,
  gold 16pt leading icon, `chip` text white, padding `10/14`.

### 7.4 Sheet
- `cream.50`, top corners `radius.xl`, `elevation.sheet`, overlaps the hero by `-24`.

### 7.5 Search panel (inset)
- Fill `cream.200`, `radius.lg`, padding `16`.
- Header row: `h3` title with gold 18pt leading icon, right-aligned text action
  (`label`, colour `gold.700`) — text actions on cream are always `gold.700`.

### 7.6 Date field tile
- Fill `cream.300`, `radius.md`, padding `12/14`, min height 68.
- Label `label` in `text.secondary`; value `body.lg` weight 700 in `text.primary`;
  gold 16pt calendar icon sits inline before the value.
- Two tiles side by side, gap `12`, equal width.
- Filled state: value shows the date; empty state shows "Select" in `text.primary`
  (not a grey placeholder — this reads as an action, not an empty input).
- Error: 1px border `danger`, message below in `caption` / `danger`.

### 7.7 Stepper (Guests)
- Label `body.lg` weight 600 left; control group right.
- Buttons 44×44, `radius.full`, fill `gold.100`, glyph `#8A6808` 20pt.
- Value `body.lg` weight 700, min width 32, centred, tabular figures.
- Disabled at bounds: fill `gold.200`, glyph at 40% opacity. Never hide the button.

### 7.8 Trust chips
- Row of three, equal width, gap `10`.
- Fill `cream.0`, `radius.md`, 1px border `cream.400`, padding `12/10`, min height 72.
- Gold 18pt icon top-left, then `chip` text in `text.primary`, up to 2 lines.
- Decorative — mark `accessibilityElementsHidden` if the label is duplicated elsewhere.

### 7.9 Primary button
- Full width, height 56, `radius.button`, fill `gold.500`, label `button` in `#1A1A1A`.
- Rest `elevation.gold`; pressed fill `gold.300` + scale `0.98` + no shadow.
- Disabled fill `gold.200`, label `#1A1A1A` at 45%.
- Loading: replace label with a 20pt `#1A1A1A` spinner, keep width fixed.

### 7.10 Secondary button
- Height 52, `radius.button`, transparent fill, 1.5px border `gold.500`,
  label `button` in `gold.700`.

### 7.11 Ghost / text button
- Label `label` in `gold.700`, no fill, no border, 44pt tap target.

### 7.12 Listing card
- `cream.0`, `radius.lg`, `elevation.md`, image 16:10 with top corners `radius.lg`.
- Title `h3`; location `caption` in `text.muted`; amenity tags `radius.sm`,
  fill `cream.100`, `chip` text in `text.secondary`.
- Price row: `AED` in `caption`/`text.muted` + amount `subtitle` weight 700 in
  `text.primary` + `/ night` in `caption`/`text.muted`. Tabular figures.

### 7.13 Status badge
- `radius.sm`, padding `4/10`, `chip` text, fill = status colour at 12% opacity,
  text = the status colour at full strength. Use the §1.5 booking status table.

### 7.14 Bottom navigation
- Height 64 + safe area, fill `navy.700`, top border `navy.400`.
- Inactive icon/label `navy.300`; active `gold.500` with a 3pt gold dot below.
- Icons 24pt, labels `overline` without the uppercase tracking (11/14, weight 600).

---

## 8. Iconography

- Line icons, **1.75pt stroke**, round caps and joins, 24pt canvas (20pt inline).
- On cream: `gold.600`. On navy: `gold.500`. Neutral UI chrome: `text.secondary`.
- Recommended set: Phosphor (Regular) or Lucide — both match the stroke weight.
- Never mix filled and outlined icons in the same row.

---

## 9. Imagery

- Warm, low-contrast, blue-hour or golden-hour photography. Dubai skyline, water,
  marble, brass, linen.
- Always apply the §7.1 scrim under text.
- Corner radius on inline photos: `radius.lg`.
- Placeholder while loading: `cream.200` with a subtle shimmer, never grey.

---

## 10. Dark theme mapping

The brand is already navy-forward, so dark mode inverts the sheet, not the brand.

| Light | Dark |
|---|---|
| `cream.50` (sheet) | `#0E2038` |
| `cream.0` (card) | `#16304F` |
| `cream.200` (panel) | `#132B48` |
| `cream.300` (field) | `#1D3A5C` |
| `cream.400` (border) | `#2B4E75` |
| `text.primary` | `#F5F1EA` |
| `text.secondary` | `#C8D3E0` |
| `text.muted` | `#8FA3B8` |
| Gold text on surface | `#DCBC7E` (not `gold.700` — it fails on navy) |
| Primary button | unchanged: `gold.500` fill, `#1A1A1A` label |

---

## 11. Content & formatting

- Currency: `AED 1,250` — code first, space, grouped thousands, no decimals for
  nightly rates; two decimals in invoices and statements.
- Dates: `Thu, 12 Mar` in UI; `12 Mar 2026` in documents. Never `MM/DD`.
- Sentence case for all UI text. Title Case only for property names.
- Nightly rate is always qualified: `/ night`. Totals always say what they include.

---

## 12. Accessibility checklist

- [ ] All tap targets ≥ 44×44.
- [ ] No `gold.500` text on cream below 24pt (use `gold.700`).
- [ ] No white text on gold fills.
- [ ] Every icon-only button has an accessibility label.
- [ ] Status is never communicated by colour alone — pair with the status word.
- [ ] Layout survives Dynamic Type at 200% (chips wrap to 2 columns, don't clip).
- [ ] Reduce Motion honoured.
- [ ] Focus order follows visual order on every screen.

---

## 13. Implementation snippets

### CSS custom properties
```css
:root {
  --navy-900:#0A1729; --navy-800:#0E2038; --navy-700:#10233C;
  --navy-600:#16304F; --navy-500:#1D3A5C; --navy-400:#2B4E75; --navy-300:#5B7799;
  --gold-50:#FBF3E4; --gold-100:#F7E3BE; --gold-200:#EDD5A3; --gold-300:#DCBC7E;
  --gold-400:#D0AA66; --gold-500:#C6A15E; --gold-600:#B8860B; --gold-700:#8A6808;
  --cream-0:#FFFFFF; --cream-50:#FCF9F4; --cream-100:#F7F5F0; --cream-200:#F6EEE1;
  --cream-300:#F1E5D2; --cream-400:#E8E4DC; --cream-500:#DCD4C6;
  --text-primary:#1A1A1A; --text-secondary:#4A4A4A; --text-muted:#6B7280;
  --text-on-navy:#FFFFFF; --text-on-navy-muted:#C8D3E0; --text-on-gold:#1A1A1A;
  --success:#059669; --warning:#D97706; --danger:#DC2626; --info:#0284C7;
  --radius-sm:10px; --radius-md:14px; --radius-lg:20px; --radius-xl:28px;
  --radius-button:16px; --radius-full:999px;
}
```

### React Native (theme.ts)
```ts
export const theme = {
  color: {
    navy:  { 900:'#0A1729', 800:'#0E2038', 700:'#10233C', 600:'#16304F',
             500:'#1D3A5C', 400:'#2B4E75', 300:'#5B7799' },
    gold:  { 50:'#FBF3E4', 100:'#F7E3BE', 200:'#EDD5A3', 300:'#DCBC7E',
             400:'#D0AA66', 500:'#C6A15E', 600:'#B8860B', 700:'#8A6808' },
    cream: { 0:'#FFFFFF', 50:'#FCF9F4', 100:'#F7F5F0', 200:'#F6EEE1',
             300:'#F1E5D2', 400:'#E8E4DC', 500:'#DCD4C6' },
    text:  { primary:'#1A1A1A', secondary:'#4A4A4A', muted:'#6B7280',
             onNavy:'#FFFFFF', onNavyMuted:'#C8D3E0', onGold:'#1A1A1A' },
    success:'#059669', warning:'#D97706', danger:'#DC2626', info:'#0284C7',
  },
  space:  { 1:4, 2:8, 3:12, 4:16, 5:20, 6:24, 8:32, 10:40, 12:48 },
  radius: { sm:10, md:14, lg:20, xl:28, button:16, full:999 },
  font:   { serif:'PlayfairDisplay', sans:'PlusJakartaSans' },
} as const;
```

### Flutter (theme.dart)
```dart
class ArsColors {
  static const navy700  = Color(0xFF10233C);
  static const navy600  = Color(0xFF16304F);
  static const gold500  = Color(0xFFC6A15E);
  static const gold100  = Color(0xFFF7E3BE);
  static const gold700  = Color(0xFF8A6808);
  static const cream50  = Color(0xFFFCF9F4);
  static const cream200 = Color(0xFFF6EEE1);
  static const cream300 = Color(0xFFF1E5D2);
  static const cream400 = Color(0xFFE8E4DC);
  static const textPrimary = Color(0xFF1A1A1A);
  static const textMuted   = Color(0xFF6B7280);
}
```

Machine-readable source of truth: `tokens.json` in this folder. Treat that file as
canonical and generate platform themes from it rather than hand-copying hex values.
