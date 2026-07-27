# Advertising pricing page — final polish QA

Date: 2026-07-20

## Scope

Focused UX and conversion polish for `/cenovnik-za-reklamiranje`, preserving the existing public shell, live advertising inventory, package pricing, availability checks, reservation flow, analytics, accessibility, and SEO behavior.

## Visual audit

The retained editorial direction remains consistent with RazbudiSe: dark canvas, editorial serif display type, restrained orange accents, thin borders, compact hero illustration, and generous spacing. The final pass strengthens the conversion path without introducing a new visual language.

- Hero: clearer campaign-oriented copy and one dominant reservation action.
- Goals: explicit recommendation actions now reveal and highlight relevant live positions.
- Packages: exactly three live-data packages; the middle option is marked most popular; durations sit with prices; all package actions preserve reservation context.
- Catalogue: collapsed by default, dynamically summarizes the live inventory, opens for `#pozicii`, and presents compact grouped rows.
- Trust: audience figures and client logos render only when explicitly verified/approved settings exist.
- FAQ and process: the revised four-step process and seven-question FAQ reduce uncertainty near conversion.
- Accessibility: focus is moved to the first relevant position after goal selection, native disclosure semantics are retained, small copy contrast is stronger, and reduced-motion preferences are respected.

## Browser verification

Verified in the local production build at:

- 375 × 812 — no horizontal overflow; mobile hierarchy and full-width actions preserved.
- 390 × 812 — no horizontal overflow.
- 768 × 900 — no horizontal overflow; tablet hierarchy preserved.
- 1024 × 900 — no horizontal overflow; FAQ allows only one open item.
- 1440 × 1000 — no horizontal overflow; three package layout and compact catalogue verified.

Interaction evidence:

- Catalogue is collapsed on a normal page visit.
- Direct navigation to `#pozicii` expands the catalogue.
- Each goal action expands the catalogue, highlights the matching positions, and focuses the first matching `Детали` button.
- Package links include package key, package name, duration, position keys, goal, and backend-derived package price.
- The FAQ starts collapsed and keeps only one answer open at a time.
- Unverified metrics and unapproved client logos remain hidden.

## Screenshots

- `output/pricing-final-polish/final-375-hero.png`
- `output/pricing-final-polish/final-390-hero.png`
- `output/pricing-final-polish/final-768-hero.png`
- `output/pricing-final-polish/final-1024-hero.png`
- `output/pricing-final-polish/final-1440-hero.png`
- `output/pricing-final-polish/final-1440-packages.png`
- `output/pricing-final-polish/final-1440-goal-catalogue.png`
- `output/pricing-final-polish/final-1024-faq.png`

## Automated verification

- `npm test` — 152 passed.
- `npm run lint` — passed.
- `npx tsc --noEmit` — passed.
- `npm run build` — passed.
- `php artisan test tests/Feature/ManagedAds` — 138 tests, 631 assertions, passed.

Final result: passed.
