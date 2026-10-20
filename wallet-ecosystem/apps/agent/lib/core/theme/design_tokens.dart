import 'package:design_system/design_system.dart';
import 'package:flutter/material.dart';

// Agent app re-exports with app-specific aliases.
// Source of truth is OkTokens in packages/design-system.

abstract final class AppColors {
  // Brand
  static const Color primary      = OkTokens.colorBluePrimary;
  static const Color primaryLight = OkTokens.colorBlueLight;
  static const Color primaryDark  = OkTokens.colorBlueDark;
  static const Color secondary    = OkTokens.colorBlueAccent;
  static const Color accent       = OkTokens.colorBlueAccent;

  // Semantic
  static const Color success      = OkTokens.colorStatusSuccess;
  static const Color successLight = OkTokens.colorStatusSuccessBg;
  static const Color warning      = OkTokens.colorStatusWarning;
  static const Color warningLight = OkTokens.colorStatusWarningBg;
  static const Color error        = OkTokens.colorStatusError;
  static const Color errorLight   = OkTokens.colorStatusErrorBg;
  static const Color info         = OkTokens.colorStatusInfo;
  static const Color infoLight    = OkTokens.colorStatusInfoBg;

  // Float balance states
  static const Color floatNormal      = OkTokens.colorStatusSuccess;
  static const Color floatLowAmber    = OkTokens.colorStatusWarning;
  static const Color floatCriticalRed = OkTokens.colorStatusError;

  // Neutral / surface
  static const Color background      = OkTokens.colorBlueBackground;
  static const Color surface         = OkTokens.colorBlueSurface;
  static const Color onPrimary       = OkTokens.colorNeutralWhite;
  static const Color onSurface       = OkTokens.colorNeutralWhite;
  static const Color onSurfaceVariant = OkTokens.colorNeutralSecondary;
  static const Color divider         = OkTokens.colorNeutralBorder;
  static const Color outline         = OkTokens.colorNeutralBorder;
  static const Color disabled        = OkTokens.colorNeutral500;
  static const Color offlineBanner   = OkTokens.colorNavy600;
  static const Color surfaceVariant  = OkTokens.colorNavy600;

  static const Color textPrimary   = OkTokens.colorNeutralWhite;
  static const Color textSecondary = OkTokens.colorNeutralSecondary;
}

abstract final class AppTypography {
  static const String fontFamily = 'Roboto';

  static const TextStyle displayLarge = TextStyle(
    fontSize: OkTokens.fontSize4xl,
    fontWeight: OkTokens.fontWeightBold,
    letterSpacing: -0.5,
  );
  static const TextStyle displayMedium = TextStyle(
    fontSize: OkTokens.fontSize2xl,
    fontWeight: OkTokens.fontWeightBold,
  );
  static const TextStyle headlineLarge = TextStyle(
    fontSize: OkTokens.fontSizeXl,
    fontWeight: OkTokens.fontWeightSemibold,
  );
  static const TextStyle headlineMedium = TextStyle(
    fontSize: OkTokens.fontSizeLg,
    fontWeight: OkTokens.fontWeightSemibold,
  );
  static const TextStyle titleLarge = TextStyle(
    fontSize: OkTokens.fontSizeBase,
    fontWeight: OkTokens.fontWeightSemibold,
  );
  static const TextStyle titleMedium = TextStyle(
    fontSize: OkTokens.fontSizeSm,
    fontWeight: OkTokens.fontWeightSemibold,
  );
  static const TextStyle titleSmall = TextStyle(
    fontSize: 13.0,
    fontWeight: OkTokens.fontWeightSemibold,
  );
  static const TextStyle bodyLarge  = TextStyle(fontSize: OkTokens.fontSizeBase);
  static const TextStyle bodyMedium = TextStyle(fontSize: OkTokens.fontSizeSm);
  static const TextStyle bodySmall  = TextStyle(fontSize: OkTokens.fontSizeXs);
  static const TextStyle labelLarge = TextStyle(
    fontSize: OkTokens.fontSizeSm,
    fontWeight: OkTokens.fontWeightMedium,
    letterSpacing: 0.1,
  );
  static const TextStyle labelMedium = TextStyle(
    fontSize: OkTokens.fontSizeXs,
    fontWeight: OkTokens.fontWeightMedium,
    letterSpacing: 0.5,
  );
  static const TextStyle labelSmall = TextStyle(
    fontSize: 11.0,
    fontWeight: OkTokens.fontWeightNormal,
    letterSpacing: 0.5,
  );

  static const TextStyle amountLarge = TextStyle(
    fontSize: OkTokens.fontSize3xl,
    fontWeight: OkTokens.fontWeightBold,
    fontFeatures: [FontFeature.tabularFigures()],
  );
  static const TextStyle amountMedium = TextStyle(
    fontSize: OkTokens.fontSizeLg,
    fontWeight: OkTokens.fontWeightSemibold,
    fontFeatures: [FontFeature.tabularFigures()],
  );
  static const TextStyle amountSmall = TextStyle(
    fontSize: OkTokens.fontSizeSm,
    fontWeight: OkTokens.fontWeightMedium,
    fontFeatures: [FontFeature.tabularFigures()],
  );
}

abstract final class AppSpacing {
  static const double xs  = OkTokens.spacingXs;
  static const double sm  = OkTokens.spacingSm;
  static const double md  = OkTokens.spacingMd;
  static const double lg  = OkTokens.spacingLg;
  static const double xl  = OkTokens.spacingXl;
  static const double xxl = OkTokens.spacing2xl;

  static const double borderRadius   = 12.0;
  static const double borderRadiusLg = 16.0;
  static const double borderRadiusSm = 8.0;

  static const double minTouchTarget  = 48.0;
  static const double cardElevation   = 2.0;
  static const double bottomNavHeight = 56.0;
}
