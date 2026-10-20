# Feature Specification: Unified Design System & Multi-Application Theming

**Feature Branch**: `035-unified-design-system`

**Created**: 2026-10-10

**Status**: Draft

**Input**: OleKard Ecosystem — Unified Design System & Multi-Application Theme Implementation

---

## Overview

OleKard operates multiple user-facing applications — a consumer mobile wallet, an agent field application, a web administration platform, a merchant portal, a communication product (OleKard Connect), and an infrastructure product (OleKard Direct/ODSA). Today each application has independently accumulated its own visual styles, hardcoded colors, and inconsistent spacing. This produces a fragmented brand experience, accessibility gaps, and high maintenance cost when colors or component styles need to change.

This feature establishes a centralized design system with a shared token architecture and application-specific branded themes, bringing every existing application into visual alignment without altering any business logic, API, database, or security behavior.

---

## Clarifications

### Session 2026-10-10

- Q: Should the token system use code generation from a single source, or hand-edited per platform? → A: Code generation from a single JSON/YAML source (Option A) — a build step generates CSS variables for web and Dart constants/ThemeExtension for Flutter.
- Q: Should shared Flutter widgets be extracted into a new shared package, or use ThemeData configuration only? → A: ThemeData/ThemeExtension configuration only (Option B) — no new shared Flutter widget package; each app keeps its own widget files.
- Q: Does any currently deployed OleKard application have a working light-mode UI that must be preserved? → A: No — all applications are dark-mode only; no light-mode migration is in scope.
- Q: What visual regression testing approach should be used after theming is applied? → A: Flutter golden tests for mobile screens + Next.js component snapshot tests + manual review for web (Option B); no external visual regression service required.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Distinct App Identity (Priority: P1)

Each OleKard application has a recognizable, distinct visual identity tied to its purpose. A customer using the consumer wallet sees a green-toned interface. An agent sees blue. An administrator sees teal. These identities are applied consistently across every screen within each application — not just entry points.

**Why this priority**: Brand differentiation across applications reduces user confusion in the field (especially where agents use both consumer and agent devices), reinforces role-appropriate context, and satisfies the OleKard brand architecture approved by stakeholders.

**Independent Test**: Open each application and navigate through at least three screens. Verify that the dominant color family matches the approved palette for that application, that no screen within an application uses a color family belonging to a different application, and that core interactive controls (buttons, links, active navigation items) use the application's designated primary color.

**Acceptance Scenarios**:

1. **Given** a user opens the consumer wallet application, **When** they navigate through the login, dashboard, and transaction history screens, **Then** every primary interactive element and dominant background uses the approved Consumer Green palette (#16A34A primary, #001B12 background) and no teal, blue, or purple from other applications is present.

2. **Given** a user opens the agent application, **When** they navigate through authentication, dashboard, and customer lookup screens, **Then** every primary interactive element uses the approved Agent Blue palette (#2563EB primary, #001329 background).

3. **Given** an administrator opens the administration platform, **When** they navigate through the dashboard, user management, and audit logs, **Then** every primary interactive element uses the approved Administration Teal palette (#0F766E primary, #001A1D background).

4. **Given** any application is themed correctly, **When** a design token (e.g., the primary color) is updated in the centralized token source, **Then** the change propagates to every component in that application without requiring per-component color edits.

---

### User Story 2 — Accessibility-Compliant Visual Design (Priority: P2)

Every visible element across all applications meets WCAG 2.2 Level AA contrast and accessibility requirements, so that users with visual impairments, motor limitations, or assistive technology can fully use each product.

**Why this priority**: Regulatory and ethical obligations for financial services require accessible products. Accessibility issues in the current codebase (low-contrast hardcoded colors against dark backgrounds) represent a legal and reputational risk.

**Independent Test**: Run an automated accessibility audit on each application's primary screens and manually verify focus visibility, color contrast ratios, and touch target sizes. All required contrast ratios must pass; all interactive controls must have visible focus states; all status indicators must include non-color cues.

**Acceptance Scenarios**:

1. **Given** any screen in any application, **When** an automated contrast checker measures text against its background, **Then** normal body text achieves at least 4.5:1 contrast and large/heading text achieves at least 3:1 contrast.

2. **Given** a status indicator (success, error, warning, info), **When** a user with color blindness views the screen, **Then** the status is distinguishable through a secondary cue (icon, label, or pattern) in addition to color.

3. **Given** any interactive control (button, input, link), **When** a keyboard user moves focus to it, **Then** a clearly visible focus ring is displayed that meets at least 3:1 contrast against adjacent backgrounds.

4. **Given** any approved brand color is used as a button background, **When** the foreground text color is evaluated, **Then** the combination meets at least 4.5:1 contrast; where a brand color cannot achieve this with white text, an accessible derived shade or dark foreground is used without replacing the base brand color.

5. **Given** touch targets in the Flutter mobile applications, **When** measured on screen, **Then** every tappable control has a minimum 44×44 dp hit area.

---

### User Story 3 — Centralized Design Token Governance (Priority: P3)

Designers and developers maintain a single authoritative source of design tokens (colors, spacing, typography, border radii, shadows, motion) that can be updated once and propagated to all applications, reducing duplication and the risk of drift.

**Why this priority**: Without a token system, every color change requires hunting through dozens of files across multiple applications. This is the foundational enabler for long-term design maintainability.

**Independent Test**: Change one semantic token value (e.g., the semantic error color) in the shared token source. Confirm that every application that uses that semantic token reflects the change after a build, without any per-application file needing to be manually updated.

**Acceptance Scenarios**:

1. **Given** the shared primitive token source defines `semantic.error = #EF4444`, **When** a developer searches all application source files for hardcoded `#EF4444` color values, **Then** no hardcoded occurrences remain in component-level files; all references point to a semantic token.

2. **Given** a centralized spacing scale is defined (e.g., 4px base unit), **When** a developer inspects spacing values in any application's layout, **Then** all spacing values are multiples of the approved scale and referenced via tokens, not hardcoded pixel values.

3. **Given** the token architecture supports application-specific overrides, **When** a developer needs to add a new application to the ecosystem, **Then** they can create a new theme by composing existing primitive tokens and defining only the application-specific overrides.

---

### User Story 4 — Administration Platform Visual Consistency (Priority: P4)

Every module and screen within the administration platform uses the Teal theme consistently, including sidebar navigation, data tables, KPI cards, forms, dialogs, status badges, charts, and alerts — so administrators experience a cohesive, professional tool regardless of which module they are in.

**Why this priority**: The admin platform is used by operations teams managing financial transactions and customer data. Visual inconsistency erodes trust in the tool and creates cognitive load when switching between modules.

**Independent Test**: Navigate through every implemented admin module (dashboard, users, KYC, wallets, transactions, agents, audit logs, reports, settings). Verify consistent application of the Teal theme, typography, spacing, and component patterns across all screens.

**Acceptance Scenarios**:

1. **Given** an administrator navigates from the dashboard to the user management module to the audit log, **When** they compare the sidebar, table styles, and badge colors, **Then** all three modules use identical component dimensions, typography scale, and Teal primary color for interactive elements.

2. **Given** a data table exists in any admin module, **When** a developer inspects it, **Then** it uses shared table tokens for borders, row heights, header background, and hover states rather than module-specific hardcoded values.

3. **Given** a status badge is displayed (e.g., KYC pending, transaction failed), **When** rendered in the admin platform, **Then** it uses the shared semantic status tokens (`semantic.success`, `semantic.error`, `semantic.warning`, `semantic.info`) rather than hardcoded hex values.

---

### User Story 5 — Consumer App Visual Polish (Priority: P5)

Every screen in the consumer mobile application uses the approved Green theme consistently and displays the OleKard brand identity, creating a premium, trustworthy experience for customers conducting financial transactions.

**Why this priority**: The consumer wallet is OleKard's primary customer-facing product. Visual polish and brand consistency directly affect perceived trust in a financial application.

**Independent Test**: Navigate through the complete consumer app flow from onboarding through dashboard, wallet, transactions, and settings. Verify consistent Green theming, no color inconsistencies between screens, and that all screens use token-referenced colors.

**Acceptance Scenarios**:

1. **Given** a customer opens the consumer app on any screen, **When** they view the primary call-to-action button, **Then** it uses Consumer Green (#16A34A) as background with accessible foreground text.

2. **Given** a customer views their wallet balance, **When** the balance card is rendered, **Then** the card uses the Consumer Surface color (#062A1A) and the balance text meets contrast requirements against that background.

---

### User Story 6 — Agent App Visual Polish (Priority: P6)

Every screen in the agent mobile application uses the approved Blue theme, distinguishing it visually from the consumer app (which agents may also have access to), and presenting a professional tool for field operations.

**Why this priority**: Agents use multiple devices and apps in the field. Clear visual distinction between the agent and consumer contexts reduces operational errors.

**Independent Test**: Navigate through the complete agent app flow from login through dashboard, customer operations, and settings. Verify consistent Blue theming throughout.

**Acceptance Scenarios**:

1. **Given** an agent opens the agent app, **When** they see any primary interactive element, **Then** it uses Agent Blue (#2563EB) — visually distinct from the Consumer Green (#16A34A) of the consumer app.

2. **Given** the agent app and consumer app are both installed on the same device, **When** a user switches between them, **Then** the immediately visible color difference makes the active application unambiguous.

---

### Edge Cases

- What happens when a brand color fails the minimum 4.5:1 contrast ratio with white text? A documented accessible foreground (dark text or derived shade) is used without replacing the approved base brand color.
- How does the system handle applications that are not yet implemented (Merchant Portal, OleKard Connect, ODSA)? Theme definitions are created and validated, but no UI screens are built for applications that do not yet exist.
- What happens if a component references a token that does not exist in the application's theme? The build fails or warns with a missing-token error, preventing silent visual breakage.
- How does the design system handle reduced-motion preferences? Animations and transitions respect the operating system's reduced-motion accessibility setting.
- What happens to existing tests when token names change? Existing tests are updated to reference new token names; no tests are deleted or weakened.

---

## Requirements *(mandatory)*

### Functional Requirements

**Token Architecture**

- **FR-001**: The system MUST define a shared set of primitive color tokens covering the OleKard master brand palette (navy, midnight, gold, goldLight) and all application-specific palettes.
- **FR-002**: The system MUST define semantic tokens (background, foreground, primary, surface, border, success, warning, error, info, muted, etc.) that map to primitive tokens per application theme.
- **FR-003**: The system MUST define typography tokens (font families, weights, size scale) as shared primitives usable by all applications.
- **FR-004**: The system MUST define a spacing scale, border-radius scale, elevation/shadow scale, and motion tokens as shared primitives.
- **FR-005**: Every application MUST reference design tokens for all color, spacing, border, and typography values; hardcoded hex values MUST NOT appear in component-level source files.
- **FR-006**: The token architecture MUST support application-specific theme overrides without requiring fork or duplication of the entire token set. Token values MUST be defined in a single canonical source file (JSON or YAML); a build pipeline generates platform-specific outputs (CSS custom properties for web, Dart constants/ThemeExtension for Flutter). Manual duplication of token values across platform-specific files is not permitted.

**Application Themes**

- **FR-007**: The consumer application MUST use the approved Consumer Green palette as its primary theme: #16A34A primary, #001B12 background, #062A1A surface, #4ADE80 primary light, #15803D primary dark, #84CC16 accent.
- **FR-008**: The agent application MUST use the approved Agent Blue palette as its primary theme: #2563EB primary, #001329 background, #061B3A surface, #60A5FA primary light, #1D4ED8 primary dark, #38BDF8 accent.
- **FR-009**: The administration platform MUST use the approved Administration Teal palette: #0F766E primary, #001A1D background, #062A2A surface, #2DD4BF primary light, #115E59 primary dark, #14B8A6 accent.
- **FR-010**: Where the merchant portal, OleKard Connect, and OleKard Direct/ODSA applications exist in the codebase, they MUST be themed with the approved Orange, Purple, and Gold palettes respectively.
- **FR-011**: The OleKard master brand palette tokens (brand.navy, brand.midnight, brand.gold, brand.goldLight, neutral.white, neutral.secondary, neutral.border) MUST be available as shared primitives.
- **FR-012**: Semantic status colors MUST be consistent across all applications: success #22C55E, warning #F59E0B, error #EF4444, info #38BDF8.

**Design System Foundation**

- **FR-013**: The design system MUST define a consistent typography hierarchy (display, heading, body, caption, label) shared across applications with platform-appropriate implementations.
- **FR-014**: The design system MUST standardize interactive states (default, hover, pressed/active, focused, disabled) with token-referenced colors for each state.
- **FR-015**: The design system MUST define loading/skeleton, empty, error, and disabled states as reusable patterns.
- **FR-016**: Navigation patterns (sidebar for admin web, bottom navigation for mobile) MUST use consistent token-referenced colors and spacing.
- **FR-017**: Shared service icon colors MUST be defined as tokens (wallet, payments, utilities, messaging, AI, notifications, security, rewards, support, multilanguage).

**Administration Platform**

- **FR-018**: Every implemented admin module — including dashboard, users, KYC, wallets, transactions, agents, sessions, devices, audit logs, reports, notifications, settings, and security management — MUST use the Teal theme consistently.
- **FR-019**: Admin data tables, KPI cards, status badges, filters, forms, dialogs, tabs, charts, pagination, and alerts MUST all reference the same shared token set rather than per-module hardcoded values.
- **FR-020**: The admin sidebar and top navigation MUST use consistent token-referenced background, border, and active-state colors.

**Consumer Application**

- **FR-021**: Every implemented consumer screen — onboarding, registration, login, OTP, device verification, PIN setup/reset, profile, dashboard, wallet, transactions, payments, utilities, notifications, settings, and security — MUST use the Consumer Green theme.
- **FR-022**: Flutter ThemeData and ThemeExtension MUST be used for consumer app theming; per-screen hardcoded color values MUST be eliminated.

**Agent Application**

- **FR-023**: Every implemented agent screen — authentication, dashboard, customer onboarding/verification, wallet operations, transaction management, customer lookup, profile, notifications, settings, and security — MUST use the Agent Blue theme.
- **FR-024**: The agent application MUST share Flutter widget implementations with the consumer app wherever practical; theme differences MUST be achieved through ThemeData/ThemeExtension configuration, not duplicate widget code. No new shared Flutter package will be created; each application retains its own widget files.

**Accessibility**

- **FR-025**: Every text element in every application MUST meet WCAG 2.2 AA contrast ratios: 4.5:1 for normal text, 3:1 for large text.
- **FR-026**: Every UI component boundary (buttons, inputs, chart elements) MUST meet 3:1 contrast against adjacent backgrounds.
- **FR-027**: Where an approved brand color cannot achieve required contrast with white text, an accessible foreground color MUST be used; the base brand color MUST NOT be silently replaced.
- **FR-028**: All interactive controls MUST have visible focus indicators meeting 3:1 contrast.
- **FR-029**: All status indicators MUST convey state through means other than color alone (icon, label, pattern).
- **FR-030**: Mobile touch targets MUST be at least 44×44 dp.
- **FR-031**: Animations and transitions MUST respect the operating system reduced-motion preference.

**Quality Assurance**

- **FR-032**: The implementation MUST not break any existing functional behavior, business logic, API interaction, authentication flow, or RBAC rule.
- **FR-033**: Token integrity tests MUST verify that all expected tokens are defined and no token references a missing value.
- **FR-034**: Existing application tests MUST pass after theming changes; no tests may be deleted or weakened.
- **FR-035**: Each application MUST produce a successful build after theming is applied.
- **FR-036**: Flutter applications MUST include golden tests for primary screens to catch unintended visual regressions; the Next.js administration application MUST include component snapshot tests for themed components. No external visual regression service (e.g., Chromatic) is required for this feature.

### Key Entities

- **Design Token**: A named value (color, size, spacing, motion duration) that serves as the single authoritative reference for a visual attribute. Tokens have three tiers: primitive (raw values), semantic (role-based references to primitives), and component-level (specific uses within a component).
- **Application Theme**: A complete set of semantic token mappings tailored to a specific application's brand identity, composed from shared primitives with application-specific overrides.
- **Brand Palette**: The approved set of HEX color values for the OleKard master brand and each application. These values are the authoritative source; derived or accessible variants are generated from them, not substituted for them.
- **Component Token**: A token scoped to a specific UI component type (e.g., button primary background, table row hover) that references a semantic token.
- **Accessibility Adjustment**: A documented case where an approved brand color requires a modified foreground or derived shade to achieve required contrast, recorded with rationale and the original brand color preserved.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All implemented OleKard applications load with their correct application-specific color theme and show zero hardcoded hex color values in component-level source files.
- **SC-002**: Automated contrast checks pass for 100% of text elements across all primary screens in all applications, achieving WCAG 2.2 AA (4.5:1 for normal text, 3:1 for large text).
- **SC-003**: UI component contrast checks pass for 100% of interactive controls across all applications (3:1 minimum).
- **SC-004**: All existing automated tests pass after theme implementation; no test suite reports regressions attributable to theming changes.
- **SC-005**: Each application produces a clean build with no token-reference errors.
- **SC-006**: A developer can update any semantic token value in the centralized token source and verify the change propagates to all applications in a single build cycle without per-application file edits.
- **SC-007**: Every interactive control across all applications has a visible keyboard/focus state that passes contrast validation.
- **SC-008**: 100% of status indicators (success, error, warning, info) in all applications include a non-color secondary cue (icon or label) in addition to their semantic color.
- **SC-009**: All Flutter mobile applications pass `flutter analyze` with no issues after theming is applied.
- **SC-010**: Animations and transitions in all applications are suppressed or reduced when the user's device has reduced-motion enabled.

---

## Assumptions

- The approved HEX values specified in this feature (Consumer Green #16A34A, Agent Blue #2563EB, Administration Teal #0F766E, etc.) are final and authoritative; they will not be altered during implementation.
- Where a derived accessible shade or dark foreground is required for contrast compliance, the adjustment is documented and the base brand color is preserved in the token source.
- The Merchant Portal, OleKard Connect, and OleKard Direct/ODSA applications may not yet have substantial UI implemented; theme definitions will be created for them but no screens will be built beyond what already exists.
- Existing business logic, API contracts, authentication flows, RBAC, database schemas, and security behaviors are out of scope and will not be changed.
- The shared Flutter widget approach means agent and consumer apps share widget implementations and differ only through their ThemeData/ThemeExtension configuration; this is already the architectural direction established in E31 and E32. No new shared Flutter package will be created; each application retains its own widget files.
- A single JSON or YAML file serves as the canonical design token source; a build pipeline (e.g., Style Dictionary or equivalent) generates platform-specific outputs — CSS custom properties for web applications and Dart constants/ThemeExtension for Flutter. This pipeline lives in a dedicated package (e.g., `packages/design-tokens/`) within the monorepo.
- The administration platform is a Next.js web application using Tailwind CSS and shadcn/ui; generated CSS custom properties are the delivery mechanism for web tokens.
- All applications are dark-mode only. No application currently has a working light-mode UI. Light-mode variants are out of scope for this feature and MUST NOT be created unless a specific accessibility requirement cannot be met within the dark theme.
- The monorepo structure already exists and will be used without alteration to host the token source.
- Test infrastructure (Flutter widget tests, Next.js component tests) exists and will be extended, not replaced.
- Visual regression baselines will be captured after theming is confirmed correct; blind snapshot updates are not permitted.
