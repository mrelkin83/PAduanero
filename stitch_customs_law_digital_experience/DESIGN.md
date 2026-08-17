---
name: Lex Aeterna
colors:
  surface: '#111415'
  surface-dim: '#111415'
  surface-bright: '#37393b'
  surface-container-lowest: '#0c0e10'
  surface-container-low: '#1a1c1d'
  surface-container: '#1e2021'
  surface-container-high: '#282a2c'
  surface-container-highest: '#333537'
  on-surface: '#e2e2e4'
  on-surface-variant: '#c7c6ca'
  inverse-surface: '#e2e2e4'
  inverse-on-surface: '#2f3132'
  outline: '#919094'
  outline-variant: '#46464a'
  surface-tint: '#c8c6c7'
  primary: '#c8c6c7'
  on-primary: '#313031'
  primary-container: '#0a0a0b'
  on-primary-container: '#7a797a'
  inverse-primary: '#5f5e5f'
  secondary: '#e6c185'
  on-secondary: '#422c00'
  secondary-container: '#5e4515'
  on-secondary-container: '#d6b379'
  tertiary: '#c8c6c8'
  on-tertiary: '#303032'
  tertiary-container: '#0a0a0c'
  on-tertiary-container: '#7a797b'
  error: '#ffb4ab'
  on-error: '#690005'
  error-container: '#93000a'
  on-error-container: '#ffdad6'
  primary-fixed: '#e5e2e3'
  primary-fixed-dim: '#c8c6c7'
  on-primary-fixed: '#1c1b1c'
  on-primary-fixed-variant: '#474647'
  secondary-fixed: '#ffdeaa'
  secondary-fixed-dim: '#e6c185'
  on-secondary-fixed: '#271900'
  on-secondary-fixed-variant: '#5b4313'
  tertiary-fixed: '#e4e2e4'
  tertiary-fixed-dim: '#c8c6c8'
  on-tertiary-fixed: '#1b1b1d'
  on-tertiary-fixed-variant: '#474649'
  background: '#111415'
  on-background: '#e2e2e4'
  surface-variant: '#333537'
typography:
  display-xl:
    fontFamily: Geist
    fontSize: 80px
    fontWeight: '600'
    lineHeight: '1.1'
    letterSpacing: -0.04em
  display-xl-mobile:
    fontFamily: Geist
    fontSize: 48px
    fontWeight: '600'
    lineHeight: '1.1'
    letterSpacing: -0.03em
  headline-lg:
    fontFamily: Geist
    fontSize: 48px
    fontWeight: '500'
    lineHeight: '1.2'
    letterSpacing: -0.02em
  headline-lg-mobile:
    fontFamily: Geist
    fontSize: 32px
    fontWeight: '500'
    lineHeight: '1.2'
    letterSpacing: -0.02em
  body-large:
    fontFamily: Geist
    fontSize: 20px
    fontWeight: '400'
    lineHeight: '1.6'
    letterSpacing: 0em
  body-main:
    fontFamily: Geist
    fontSize: 16px
    fontWeight: '400'
    lineHeight: '1.6'
    letterSpacing: 0em
  label-caps:
    fontFamily: Geist
    fontSize: 12px
    fontWeight: '600'
    lineHeight: '1.0'
    letterSpacing: 0.15em
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  unit: 8px
  gutter: 24px
  margin-desktop: 80px
  margin-mobile: 24px
  section-gap: 160px
---

## Brand & Style

The design system is engineered to evoke **absolute authority, surgical precision, and quiet exclusivity**. Targeting high-net-worth individuals and corporate entities involved in global trade, the aesthetic moves away from traditional legal "clutter" toward a **Premium Minimalist** direction, heavily influenced by high-end consumer technology interfaces.

The UI communicates reliability through massive negative space and a "less but better" philosophy. It balances the coldness of technological precision with the warmth of a subtle metallic accent, suggesting a firm that is both modern and deeply established. The emotional response is one of security: the user should feel they are in the hands of a specialist who values clarity above all else.

## Colors

The palette is anchored in **Deep Black (#0A0A0B)** to establish a foundation of mystery and gravity. Surfaces are layered using **Graphite Gray (#1C1C1E)** to create depth without relying on traditional drop shadows.

- **Deep Black:** The primary canvas, providing a cinematic backdrop.
- **Pure White / Off-White:** Used exclusively for high-contrast typography and essential icons.
- **Metallic Gold (#C5A36A):** A surgical accent color used for "moments of authority"—victory counters, active states, and premium underlines.
- **Graphite Gray:** Used for containers and background sections to separate content modules elegantly.

## Typography

This design system utilizes **Geist** for its technical, monolinear precision. Typography is the primary decorative element; headlines are treated as monuments, surrounded by vast amounts of white space to emphasize the weight of the statement.

For mobile, display sizes scale down aggressively to maintain legibility while preserving the high-contrast ratio between headers and body text. **Label Caps** are used for secondary metadata and breadcrumbs to provide a functional, data-driven look reminiscent of shipping manifests and legal filing systems.

## Layout & Spacing

The system follows a **12-column fixed grid** on desktop with generous outer margins to keep content centered and focused. The spacing rhythm is intentionally "loose" to convey luxury—standard section gaps are unusually large (160px) to prevent the user from feeling overwhelmed by information.

- **Desktop:** 80px side margins, 24px gutters.
- **Mobile:** 24px side margins, 16px gutters.
- **Consistency:** All components and internal spacing follow a strict 8px base unit. 

Content should be aligned with significant "breathing room" (negative space), ensuring that the most critical legal advice or call-to-action stands in isolation.

## Elevation & Depth

Depth is achieved through **Tonal Layering** and **Micro-Glassmorphism**. Rather than traditional heavy shadows, the system uses variations in surface luminosity:

1.  **Base Layer:** Deep Black (#0A0A0B).
2.  **Surface Layer:** Graphite Gray (#1C1C1E) used for cards and modals.
3.  **Glass Effect:** Navigation bars and floating action buttons use a 20% transparent Graphite background with a heavy (32px) backdrop blur and a 1px subtle white border (10% opacity).

This creates a "stacked" effect that feels physical yet digital and clean. Light sources are modeled as soft, top-down ambient light, creating very faint, large-radius shadows (0% to 5% opacity).

## Shapes

The shape language is **Soft (0.25rem / 4px)**. This minimal rounding provides just enough approachability to avoid the harshness of pure 90-degree corners, maintaining a professional and "engineered" appearance. 

Containers use 4px radii, while internal elements like input fields and tags use the same. Only the most prominent CTA buttons may utilize a slightly larger radius (8px) to distinguish them from structural elements, but pill-shaped buttons are strictly avoided to maintain the formal legal tone.

## Components

### Buttons
Primary buttons feature a solid Metallic Gold background with black text. Micro-interactions include a subtle "lift" effect and a slight increase in luminosity on hover. Secondary buttons are "Ghost" style with a 1px Graphite border that turns Gold on hover.

### Cards
Cards are Graphite Gray (#1C1C1E) with no border. On hover, they should reveal a 1px Metallic Gold top-border stroke, signaling focus and authority.

### Input Fields
Fields are dark, utilizing a bottom-border-only style for a sophisticated, editorial look. The border transitions from Graphite to Gold upon activation, accompanied by a subtle label float animation.

### Lists
Legal lists use monospaced numbers (Geist Mono) in Metallic Gold. Each list item is separated by a 1px line at 5% white opacity to maintain a clean, organized hierarchy without adding visual weight.

### Navigation
The header is a fixed glassmorphic bar. Links are in Label-Caps style, with a Gold dot appearing beneath the active section.