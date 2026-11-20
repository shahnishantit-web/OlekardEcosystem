import 'package:design_system/design_system.dart';
import 'package:flutter/material.dart';

// Consumer app re-exports with app-specific aliases.
// Source of truth is OkTokens in packages/design-system.

abstract final class AppColors {
  static const primary     = OkTokens.colorMBluePrimary;
  static const primaryDark = OkTokens.colorMBlueDark;
  static const primaryLight = OkTokens.colorMBlueLight;
  static const secondary   = OkTokens.colorMBlueAccent;
  static const surface     = OkTokens.colorMBlueSurface;
  static const background  = OkTokens.colorMBlueBackground;
  // Consumer blue primary uses the design system's white foreground token.
  static const onPrimary   = OkTokens.colorNeutralWhite;
  static const onSurface   = textPrimary;
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

  static const textPrimary   = OkTokens.colorMNeutralWhite;
  static const textSecondary = OkTokens.colorMNeutralSecondary;
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
