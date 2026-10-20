import 'package:design_system/design_system.dart';
import 'package:flutter/material.dart';

// Consumer app re-exports with app-specific aliases.
// Source of truth is OkTokens in packages/design-system.

abstract final class AppColors {
  static const primary     = OkTokens.colorGreenPrimary;
  static const primaryDark = OkTokens.colorGreenDark;
  static const primaryLight = OkTokens.colorGreenLight;
  static const secondary   = OkTokens.colorGreenAccent;
  static const surface     = OkTokens.colorGreenSurface;
  static const background  = OkTokens.colorGreenBackground;
  // Accessible foreground: dark text on Consumer Green primary passes 5.45:1 (FR-027)
  static const onPrimary   = OkTokens.colorForegroundOnPrimary;
  static const onSurface   = OkTokens.colorNeutralWhite;
  static const outline     = OkTokens.colorNeutralBorder;

  static const error        = OkTokens.colorStatusError;
  static const errorBg      = OkTokens.colorStatusErrorBg;
  static const success      = OkTokens.colorStatusSuccess;
  static const successBg    = OkTokens.colorStatusSuccessBg;
  static const warning      = OkTokens.colorStatusWarning;
  static const warningBg    = OkTokens.colorStatusWarningBg;
  static const info         = OkTokens.colorStatusInfo;
  static const infoBg       = OkTokens.colorStatusInfoBg;
  // Offline indicator — amber matches the warning semantic token (#F59E0B)
  static const offlineAmber = OkTokens.colorStatusWarning;

  static const textPrimary   = OkTokens.colorNeutralWhite;
  static const textSecondary = OkTokens.colorNeutralSecondary;
}

abstract final class AppTypography {
  static const headline = TextStyle(
    fontSize: OkTokens.fontSize2xl,
    fontWeight: OkTokens.fontWeightBold,
    height: OkTokens.lineHeightTight,
  );
  static const title = TextStyle(
    fontSize: OkTokens.fontSizeXl,
    fontWeight: OkTokens.fontWeightSemibold,
    height: OkTokens.lineHeightSnug,
  );
  static const body = TextStyle(
    fontSize: OkTokens.fontSizeBase,
    fontWeight: OkTokens.fontWeightNormal,
    height: OkTokens.lineHeightNormal,
  );
  static const label = TextStyle(
    fontSize: OkTokens.fontSizeSm,
    fontWeight: OkTokens.fontWeightMedium,
    height: OkTokens.lineHeightNormal,
  );
  static const caption = TextStyle(
    fontSize: OkTokens.fontSizeXs,
    fontWeight: OkTokens.fontWeightNormal,
    height: OkTokens.lineHeightNormal,
  );
  static const amount = TextStyle(
    fontSize: OkTokens.fontSize3xl,
    fontWeight: OkTokens.fontWeightBold,
    height: OkTokens.lineHeightTight,
  );
}

abstract final class AppSpacing {
  static const double xs  = OkTokens.spacingXs;
  static const double sm  = OkTokens.spacingSm;
  static const double md  = OkTokens.spacingMd;
  static const double lg  = OkTokens.spacingLg;
  static const double xl  = OkTokens.spacingXl;
  static const double xxl = OkTokens.spacing2xl;
}
