// Design Tokens — OleKard Wallet Ecosystem
// Source: packages/design-system/tokens/*.json
// DO NOT EDIT — update source files and rebuild via npm run tokens:build
import 'package:flutter/material.dart';

abstract final class OkTokens {
  // ── Scale Colors (Tailwind-compatible primitives) ──────────────

  static const Color colorBlue50  = Color(0xFFEFF6FF);
  static const Color colorBlue100 = Color(0xFFDBEAFE);
  static const Color colorBlue200 = Color(0xFFBFDBFE);
  static const Color colorBlue300 = Color(0xFF93C5FD);
  static const Color colorBlue400 = Color(0xFF60A5FA);
  static const Color colorBlue500 = Color(0xFF3B82F6);
  static const Color colorBlue600 = Color(0xFF2563EB);
  static const Color colorBlue700 = Color(0xFF1D4ED8);
  static const Color colorBlue800 = Color(0xFF1E40AF);
  static const Color colorBlue900 = Color(0xFF1E3A8A);

  static const Color colorRed50  = Color(0xFFFEF2F2);
  static const Color colorRed300 = Color(0xFFFCA5A5);
  static const Color colorRed500 = Color(0xFFEF4444);
  static const Color colorRed700 = Color(0xFFB91C1C);
  static const Color colorRed900 = Color(0xFF7F1D1D);

  static const Color colorGreen50  = Color(0xFFF0FDF4);
  static const Color colorGreen100 = Color(0xFFDCFCE7);
  static const Color colorGreen200 = Color(0xFFBBF7D0);
  static const Color colorGreen300 = Color(0xFF86EFAC);
  static const Color colorGreen400 = Color(0xFF4ADE80);
  static const Color colorGreen500 = Color(0xFF22C55E);
  static const Color colorGreen600 = Color(0xFF16A34A);
  static const Color colorGreen700 = Color(0xFF15803D);
  static const Color colorGreen800 = Color(0xFF166534);
  static const Color colorGreen900 = Color(0xFF14532D);

  static const Color colorAmber50  = Color(0xFFFFFBEB);
  static const Color colorAmber100 = Color(0xFFFEF3C7);
  static const Color colorAmber300 = Color(0xFFFCD34D);
  static const Color colorAmber500 = Color(0xFFF59E0B);
  static const Color colorAmber700 = Color(0xFFB45309);
  static const Color colorAmber800 = Color(0xFF92400E);

  static const Color colorOrange50  = Color(0xFFFFF7ED);
  static const Color colorOrange400 = Color(0xFFFB923C);
  static const Color colorOrange500 = Color(0xFFF97316);
  static const Color colorOrange600 = Color(0xFFEA580C);
  static const Color colorOrange700 = Color(0xFFC2410C);

  static const Color colorTeal50  = Color(0xFFF0FDFA);
  static const Color colorTeal100 = Color(0xFFCCFBF1);
  static const Color colorTeal300 = Color(0xFF5EEAD4);
  static const Color colorTeal400 = Color(0xFF2DD4BF);
  static const Color colorTeal500 = Color(0xFF14B8A6);
  static const Color colorTeal600 = Color(0xFF0D9488);
  static const Color colorTeal700 = Color(0xFF0F766E);
  static const Color colorTeal800 = Color(0xFF115E59);
  static const Color colorTeal900 = Color(0xFF134E4A);

  static const Color colorViolet50  = Color(0xFFF5F3FF);
  static const Color colorViolet100 = Color(0xFFEDE9FE);
  static const Color colorViolet300 = Color(0xFFC4B5FD);
  static const Color colorViolet500 = Color(0xFF8B5CF6);
  static const Color colorViolet600 = Color(0xFF7C3AED);
  static const Color colorViolet700 = Color(0xFF6D28D9);
  static const Color colorViolet800 = Color(0xFF5B21B6);

  static const Color colorSky50  = Color(0xFFF0F9FF);
  static const Color colorSky300 = Color(0xFF7DD3FC);
  static const Color colorSky400 = Color(0xFF38BDF8);
  static const Color colorSky700 = Color(0xFF0369A1);

  static const Color colorNavy500 = Color(0xFF0D3160);
  static const Color colorNavy600 = Color(0xFF0A2540);
  static const Color colorNavy700 = Color(0xFF002137);
  static const Color colorNavy800 = Color(0xFF001B2E);
  static const Color colorNavy900 = Color(0xFF001426);

  static const Color colorGold50  = Color(0xFFFEFCE8);
  static const Color colorGold400 = Color(0xFFF5B82E);
  static const Color colorGold500 = Color(0xFFD4A017);
  static const Color colorGold600 = Color(0xFFB8860B);
  static const Color colorGold700 = Color(0xFF9A7209);
  static const Color colorGold800 = Color(0xFF8A6508);

  static const Color colorLime50  = Color(0xFFF7FEE7);
  static const Color colorLime400 = Color(0xFFA3E635);
  static const Color colorLime500 = Color(0xFF84CC16);

  static const Color colorNeutral0   = Color(0xFFFFFFFF);
  static const Color colorNeutral50  = Color(0xFFF9FAFB);
  static const Color colorNeutral100 = Color(0xFFF3F4F6);
  static const Color colorNeutral200 = Color(0xFFE5E7EB);
  static const Color colorNeutral300 = Color(0xFFD1D5DB);
  static const Color colorNeutral400 = Color(0xFF9CA3AF);
  static const Color colorNeutral500 = Color(0xFF6B7280);
  static const Color colorNeutral600 = Color(0xFF4B5563);
  static const Color colorNeutral700 = Color(0xFF374151);
  static const Color colorNeutral800 = Color(0xFF1F2937);
  static const Color colorNeutral900 = Color(0xFF111827);
  static const Color colorNeutral950 = Color(0xFF030712);

  // ── Master Brand ───────────────────────────────────────────────

  static const Color colorBrandNavy      = Color(0xFF001426);
  static const Color colorBrandMidnight  = Color(0xFF000B18);
  static const Color colorBrandGold      = Color(0xFFF5B82E);
  static const Color colorBrandGoldLight = Color(0xFFFFC94A);

  // ── Named Neutral Tokens ───────────────────────────────────────

  static const Color colorNeutralWhite     = Color(0xFFFFFFFF);
  static const Color colorNeutralSecondary = Color(0xFFB8C4D0);
  static const Color colorNeutralBorder    = Color(0xFF294052);

  // ── Accessible Foreground Overrides ───────────────────────────
  // #FFFFFF on #16A34A = 3.30:1 (FAIL 4.5:1). Use #001B12 on primary: 5.45:1 (PASS).
  static const Color colorForegroundOnPrimary = Color(0xFF001B12);

  // ── Consumer Green Application Palette ────────────────────────

  static const Color colorGreenPrimary    = Color(0xFF16A34A);
  static const Color colorGreenDark       = Color(0xFF15803D);
  static const Color colorGreenLight      = Color(0xFF4ADE80);
  static const Color colorGreenSurface    = Color(0xFF062A1A);
  static const Color colorGreenBackground = Color(0xFF001B12);
  static const Color colorGreenAccent     = Color(0xFF84CC16);

  // ── Agent Blue Application Palette ────────────────────────────

  static const Color colorBluePrimary    = Color(0xFF2563EB);
  static const Color colorBlueDark       = Color(0xFF1D4ED8);
  static const Color colorBlueLight      = Color(0xFF60A5FA);
  static const Color colorBlueSurface    = Color(0xFF061B3A);
  static const Color colorBlueBackground = Color(0xFF001329);
  static const Color colorBlueAccent     = Color(0xFF38BDF8);

  // ── Administration Teal Application Palette ───────────────────

  static const Color colorTealPrimary    = Color(0xFF0F766E);
  static const Color colorTealDark       = Color(0xFF115E59);
  static const Color colorTealLight      = Color(0xFF2DD4BF);
  static const Color colorTealSurface    = Color(0xFF062A2A);
  static const Color colorTealBackground = Color(0xFF001A1D);
  static const Color colorTealAccent     = Color(0xFF14B8A6);

  // ── Merchant Orange Application Palette ───────────────────────

  static const Color colorOrangePrimary    = Color(0xFFF97316);
  static const Color colorOrangeDark       = Color(0xFFC2410C);
  static const Color colorOrangeLight      = Color(0xFFFB923C);
  static const Color colorOrangeSurface    = Color(0xFF351407);
  static const Color colorOrangeBackground = Color(0xFF1A0A03);
  static const Color colorOrangeAccent     = Color(0xFFFBBF24);

  // ── Connect Purple Application Palette ────────────────────────

  static const Color colorPurplePrimary    = Color(0xFF7C3AED);
  static const Color colorPurpleDark       = Color(0xFF5B21B6);
  static const Color colorPurpleLight      = Color(0xFFA78BFA);
  static const Color colorPurpleSurface    = Color(0xFF1A0B36);
  static const Color colorPurpleBackground = Color(0xFF090016);
  static const Color colorPurpleAccent     = Color(0xFFC084FC);
  static const Color colorPurpleAiGlow     = Color(0xFFE879F9);

  // ── ODSA Gold Application Palette ─────────────────────────────

  static const Color colorGoldPrimary    = Color(0xFFD4A017);
  static const Color colorGoldDark       = Color(0xFF8A6508);
  static const Color colorGoldLight      = Color(0xFFF6C453);
  static const Color colorGoldSurface    = Color(0xFF302000);
  static const Color colorGoldBackground = Color(0xFF120D00);
  static const Color colorGoldAccent     = Color(0xFFF5B82E);

  // ── Shared Semantic Status ─────────────────────────────────────

  static const Color colorStatusSuccess = Color(0xFF22C55E);
  static const Color colorStatusWarning = Color(0xFFF59E0B);
  static const Color colorStatusError   = Color(0xFFEF4444);
  static const Color colorStatusInfo    = Color(0xFF38BDF8);

  static const Color colorStatusSuccessBg = Color(0xFF052E16);
  static const Color colorStatusWarningBg = Color(0xFF1C1305);
  static const Color colorStatusErrorBg   = Color(0xFF1A0505);
  static const Color colorStatusInfoBg    = Color(0xFF041520);

  static const Color colorStatusSuccessBorder = Color(0xFF15803D);
  static const Color colorStatusWarningBorder = Color(0xFFB45309);
  static const Color colorStatusErrorBorder   = Color(0xFFB91C1C);
  static const Color colorStatusInfoBorder    = Color(0xFF0369A1);

  // ── Service Icon Colors ────────────────────────────────────────

  static const Color colorServiceWallet        = Color(0xFFF5B82E);
  static const Color colorServicePayments      = Color(0xFF2563EB);
  static const Color colorServiceUtilities     = Color(0xFF22C55E);
  static const Color colorServiceMessaging     = Color(0xFF06B6D4);
  static const Color colorServiceAi            = Color(0xFF8B5CF6);
  static const Color colorServiceNotifications = Color(0xFFF59E0B);
  static const Color colorServiceSecurity      = Color(0xFF10B981);
  static const Color colorServiceRewards       = Color(0xFFEC4899);
  static const Color colorServiceSupport       = Color(0xFF14B8A6);
  static const Color colorServiceMultilanguage = Color(0xFF3B82F6);

  // ── Spacing Scale ──────────────────────────────────────────────

  static const double spacing1  = 4.0;
  static const double spacing2  = 8.0;
  static const double spacing4  = 16.0;
  static const double spacing6  = 24.0;
  static const double spacing8  = 32.0;
  static const double spacing10 = 40.0;
  static const double spacing12 = 48.0;
  static const double spacing16 = 64.0;
  static const double spacing20 = 80.0;
  static const double spacing24 = 96.0;
  static const double spacing32 = 128.0;

  // Semantic spacing aliases
  static const double spacingXs  = spacing1;
  static const double spacingSm  = spacing2;
  static const double spacingMd  = spacing4;
  static const double spacingLg  = spacing6;
  static const double spacingXl  = spacing8;
  static const double spacing2xl = spacing12;

  // ── Typography Scale ────────────────────────────────────────────

  static const double fontSizeXs   = 12.0;
  static const double fontSizeSm   = 14.0;
  static const double fontSizeBase = 16.0;
  static const double fontSizeLg   = 18.0;
  static const double fontSizeXl   = 20.0;
  static const double fontSize2xl  = 24.0;
  static const double fontSize3xl  = 30.0;
  static const double fontSize4xl  = 36.0;

  static const FontWeight fontWeightNormal   = FontWeight.w400;
  static const FontWeight fontWeightMedium   = FontWeight.w500;
  static const FontWeight fontWeightSemibold = FontWeight.w600;
  static const FontWeight fontWeightBold     = FontWeight.w700;

  static const double lineHeightTight   = 1.25;
  static const double lineHeightSnug    = 1.375;
  static const double lineHeightNormal  = 1.5;
  static const double lineHeightRelaxed = 1.625;
}
