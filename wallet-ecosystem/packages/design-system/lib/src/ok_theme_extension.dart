import 'package:flutter/material.dart';
import 'tokens.dart';

class OkThemeExtension extends ThemeExtension<OkThemeExtension> {
  const OkThemeExtension({
    required this.primary,
    required this.primaryDark,
    required this.primaryLight,
    required this.accent,
    required this.appBackground,
    required this.appSurface,
    required this.successBg,
    required this.successFg,
    required this.successBorder,
    required this.warningBg,
    required this.warningFg,
    required this.warningBorder,
    required this.errorBg,
    required this.errorFg,
    required this.errorBorder,
    required this.infoBg,
    required this.infoFg,
    required this.infoBorder,
    required this.navActive,
  });

  final Color primary;
  final Color primaryDark;
  final Color primaryLight;
  final Color accent;
  final Color appBackground;
  final Color appSurface;
  final Color successBg;
  final Color successFg;
  final Color successBorder;
  final Color warningBg;
  final Color warningFg;
  final Color warningBorder;
  final Color errorBg;
  final Color errorFg;
  final Color errorBorder;
  final Color infoBg;
  final Color infoFg;
  final Color infoBorder;
  final Color navActive;

  factory OkThemeExtension.consumer() => const OkThemeExtension(
        primary:       OkTokens.colorGreenPrimary,
        primaryDark:   OkTokens.colorGreenDark,
        primaryLight:  OkTokens.colorGreenLight,
        accent:        OkTokens.colorGreenAccent,
        appBackground: OkTokens.colorGreenBackground,
        appSurface:    OkTokens.colorGreenSurface,
        successBg:     OkTokens.colorStatusSuccessBg,
        successFg:     OkTokens.colorStatusSuccess,
        successBorder: OkTokens.colorStatusSuccessBorder,
        warningBg:     OkTokens.colorStatusWarningBg,
        warningFg:     OkTokens.colorStatusWarning,
        warningBorder: OkTokens.colorStatusWarningBorder,
        errorBg:       OkTokens.colorStatusErrorBg,
        errorFg:       OkTokens.colorStatusError,
        errorBorder:   OkTokens.colorStatusErrorBorder,
        infoBg:        OkTokens.colorStatusInfoBg,
        infoFg:        OkTokens.colorStatusInfo,
        infoBorder:    OkTokens.colorStatusInfoBorder,
        navActive:     Color.fromRGBO(22, 163, 74, 0.15),
      );

  factory OkThemeExtension.agent() => const OkThemeExtension(
        primary:       OkTokens.colorBluePrimary,
        primaryDark:   OkTokens.colorBlueDark,
        primaryLight:  OkTokens.colorBlueLight,
        accent:        OkTokens.colorBlueAccent,
        appBackground: OkTokens.colorBlueBackground,
        appSurface:    OkTokens.colorBlueSurface,
        successBg:     OkTokens.colorStatusSuccessBg,
        successFg:     OkTokens.colorStatusSuccess,
        successBorder: OkTokens.colorStatusSuccessBorder,
        warningBg:     OkTokens.colorStatusWarningBg,
        warningFg:     OkTokens.colorStatusWarning,
        warningBorder: OkTokens.colorStatusWarningBorder,
        errorBg:       OkTokens.colorStatusErrorBg,
        errorFg:       OkTokens.colorStatusError,
        errorBorder:   OkTokens.colorStatusErrorBorder,
        infoBg:        OkTokens.colorStatusInfoBg,
        infoFg:        OkTokens.colorStatusInfo,
        infoBorder:    OkTokens.colorStatusInfoBorder,
        navActive:     Color.fromRGBO(37, 99, 235, 0.15),
      );

  @override
  OkThemeExtension copyWith({
    Color? primary,
    Color? primaryDark,
    Color? primaryLight,
    Color? accent,
    Color? appBackground,
    Color? appSurface,
    Color? successBg,
    Color? successFg,
    Color? successBorder,
    Color? warningBg,
    Color? warningFg,
    Color? warningBorder,
    Color? errorBg,
    Color? errorFg,
    Color? errorBorder,
    Color? infoBg,
    Color? infoFg,
    Color? infoBorder,
    Color? navActive,
  }) =>
      OkThemeExtension(
        primary:       primary       ?? this.primary,
        primaryDark:   primaryDark   ?? this.primaryDark,
        primaryLight:  primaryLight  ?? this.primaryLight,
        accent:        accent        ?? this.accent,
        appBackground: appBackground ?? this.appBackground,
        appSurface:    appSurface    ?? this.appSurface,
        successBg:     successBg     ?? this.successBg,
        successFg:     successFg     ?? this.successFg,
        successBorder: successBorder ?? this.successBorder,
        warningBg:     warningBg     ?? this.warningBg,
        warningFg:     warningFg     ?? this.warningFg,
        warningBorder: warningBorder ?? this.warningBorder,
        errorBg:       errorBg       ?? this.errorBg,
        errorFg:       errorFg       ?? this.errorFg,
        errorBorder:   errorBorder   ?? this.errorBorder,
        infoBg:        infoBg        ?? this.infoBg,
        infoFg:        infoFg        ?? this.infoFg,
        infoBorder:    infoBorder    ?? this.infoBorder,
        navActive:     navActive     ?? this.navActive,
      );

  @override
  OkThemeExtension lerp(OkThemeExtension? other, double t) {
    if (other == null) return this;
    return OkThemeExtension(
      primary:       Color.lerp(primary,       other.primary,       t)!,
      primaryDark:   Color.lerp(primaryDark,   other.primaryDark,   t)!,
      primaryLight:  Color.lerp(primaryLight,  other.primaryLight,  t)!,
      accent:        Color.lerp(accent,        other.accent,        t)!,
      appBackground: Color.lerp(appBackground, other.appBackground, t)!,
      appSurface:    Color.lerp(appSurface,    other.appSurface,    t)!,
      successBg:     Color.lerp(successBg,     other.successBg,     t)!,
      successFg:     Color.lerp(successFg,     other.successFg,     t)!,
      successBorder: Color.lerp(successBorder, other.successBorder, t)!,
      warningBg:     Color.lerp(warningBg,     other.warningBg,     t)!,
      warningFg:     Color.lerp(warningFg,     other.warningFg,     t)!,
      warningBorder: Color.lerp(warningBorder, other.warningBorder, t)!,
      errorBg:       Color.lerp(errorBg,       other.errorBg,       t)!,
      errorFg:       Color.lerp(errorFg,       other.errorFg,       t)!,
      errorBorder:   Color.lerp(errorBorder,   other.errorBorder,   t)!,
      infoBg:        Color.lerp(infoBg,        other.infoBg,        t)!,
      infoFg:        Color.lerp(infoFg,        other.infoFg,        t)!,
      infoBorder:    Color.lerp(infoBorder,    other.infoBorder,    t)!,
      navActive:     Color.lerp(navActive,     other.navActive,     t)!,
    );
  }
}
