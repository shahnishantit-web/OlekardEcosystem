#!/usr/bin/env node
/**
 * Token validation script — verifies all contract tokens are present in generated outputs.
 * Usage: node scripts/validate-tokens.js
 * Exit code: 0 = pass, 1 = failures
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const cssPath = path.join(root, 'build/css/tokens.css');
const dartPath = path.join(root, 'lib/src/tokens.dart');

// ── Contract token names ──────────────────────────────────────────────────────

const CSS_TOKENS = [
  // Master brand
  '--color-brand-navy', '--color-brand-midnight', '--color-brand-gold', '--color-brand-gold-light',
  '--color-neutral-white', '--color-neutral-secondary', '--color-neutral-border',
  // Status
  '--color-status-success', '--color-status-warning', '--color-status-error', '--color-status-info',
  '--color-status-success-bg', '--color-status-warning-bg', '--color-status-error-bg', '--color-status-info-bg',
  '--color-status-success-border', '--color-status-warning-border', '--color-status-error-border', '--color-status-info-border',
  // Consumer Green
  '--color-green-primary', '--color-green-dark', '--color-green-light', '--color-green-surface', '--color-green-background', '--color-green-accent',
  // Agent Blue
  '--color-blue-primary', '--color-blue-dark', '--color-blue-light', '--color-blue-surface', '--color-blue-background', '--color-blue-accent',
  // Admin Teal
  '--color-teal-primary', '--color-teal-dark', '--color-teal-light', '--color-teal-surface', '--color-teal-background', '--color-teal-accent',
  // Merchant Orange
  '--color-orange-primary', '--color-orange-dark', '--color-orange-light', '--color-orange-surface', '--color-orange-background', '--color-orange-accent',
  // Connect Purple
  '--color-purple-primary', '--color-purple-dark', '--color-purple-light', '--color-purple-surface', '--color-purple-background', '--color-purple-accent', '--color-purple-ai-glow',
  // ODSA Gold
  '--color-gold-primary', '--color-gold-dark', '--color-gold-light', '--color-gold-surface', '--color-gold-background', '--color-gold-accent',
  // Service icons
  '--color-service-wallet', '--color-service-payments', '--color-service-utilities', '--color-service-messaging',
  '--color-service-ai', '--color-service-notifications', '--color-service-security', '--color-service-rewards',
  '--color-service-support', '--color-service-multilanguage',
];

const DART_TOKENS = [
  // Master brand
  'colorBrandNavy', 'colorBrandMidnight', 'colorBrandGold', 'colorBrandGoldLight',
  'colorNeutralWhite', 'colorNeutralSecondary', 'colorNeutralBorder',
  // Status
  'colorStatusSuccess', 'colorStatusWarning', 'colorStatusError', 'colorStatusInfo',
  'colorStatusSuccessBg', 'colorStatusWarningBg', 'colorStatusErrorBg', 'colorStatusInfoBg',
  'colorStatusSuccessBorder', 'colorStatusWarningBorder', 'colorStatusErrorBorder', 'colorStatusInfoBorder',
  // Consumer Green
  'colorGreenPrimary', 'colorGreenDark', 'colorGreenLight', 'colorGreenSurface', 'colorGreenBackground', 'colorGreenAccent',
  // Agent Blue
  'colorBluePrimary', 'colorBlueDark', 'colorBlueLight', 'colorBlueSurface', 'colorBlueBackground', 'colorBlueAccent',
  // Admin Teal
  'colorTealPrimary', 'colorTealDark', 'colorTealLight', 'colorTealSurface', 'colorTealBackground', 'colorTealAccent',
  // Merchant Orange
  'colorOrangePrimary', 'colorOrangeDark', 'colorOrangeLight', 'colorOrangeSurface', 'colorOrangeBackground', 'colorOrangeAccent',
  // Connect Purple
  'colorPurplePrimary', 'colorPurpleDark', 'colorPurpleLight', 'colorPurpleSurface', 'colorPurpleBackground', 'colorPurpleAccent', 'colorPurpleAiGlow',
  // ODSA Gold
  'colorGoldPrimary', 'colorGoldDark', 'colorGoldLight', 'colorGoldSurface', 'colorGoldBackground', 'colorGoldAccent',
  // Service icons
  'colorServiceWallet', 'colorServicePayments', 'colorServiceUtilities', 'colorServiceMessaging',
  'colorServiceAi', 'colorServiceNotifications', 'colorServiceSecurity', 'colorServiceRewards',
  'colorServiceSupport', 'colorServiceMultilanguage',
  // Spacing
  'spacingXs', 'spacingSm', 'spacingMd', 'spacingLg', 'spacingXl', 'spacing2xl',
];

// ── Validate ──────────────────────────────────────────────────────────────────

function validate(filePath, tokens, label) {
  if (!fs.existsSync(filePath)) {
    console.error(`ERROR: ${label} not found at ${filePath}`);
    return tokens.length; // all missing
  }
  const content = fs.readFileSync(filePath, 'utf8');
  const missing = tokens.filter(t => !content.includes(t));
  missing.forEach(t => console.error(`MISSING in ${label}: ${t}`));
  return missing.length;
}

const cssFails  = validate(cssPath,  CSS_TOKENS,  'tokens.css');
const dartFails = validate(dartPath, DART_TOKENS, 'tokens.dart (lib/src)');

const total = cssFails + dartFails;
if (total === 0) {
  console.log(`✅  All ${CSS_TOKENS.length} CSS tokens and ${DART_TOKENS.length} Dart tokens present.`);
  process.exit(0);
} else {
  console.error(`\n❌  ${total} token(s) missing. Fix source files and rebuild.`);
  process.exit(1);
}
