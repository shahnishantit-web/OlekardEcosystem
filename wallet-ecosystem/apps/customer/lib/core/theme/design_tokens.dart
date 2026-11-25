import 'package:flutter/material.dart';

/// Customer app visual tokens. Kept local so other OleKard apps are unaffected.
abstract final class AppColors {
  static const primary = Color(0xFF1D4ED8);
  static const primaryDark = Color(0xFF1E40AF);
  static const primaryLight = Color(0xFFDBEAFE);
  static const secondary = Color(0xFFEFF6FF);
  static const surface = Color(0xFFFFFFFF);
  static const background = Color(0xFFF4F7FC);
  static const onPrimary = Color(0xFFFFFFFF);
  static const onSurface = textPrimary;
  static const outline = Color(0xFFE2E8F0);
  static const textPrimary = Color(0xFF0F172A);
  static const textSecondary = Color(0xFF475569);
  static const error = Color(0xFFB42318);
  static const errorBg = Color(0xFFFEF3F2);
  static const success = Color(0xFF15803D);
  static const successBg = Color(0xFFECFDF3);
  static const warning = Color(0xFFB45309);
  static const warningBg = Color(0xFFFFF7ED);
  static const info = Color(0xFF1D4ED8);
  static const infoBg = Color(0xFFEFF6FF);
  static const offlineAmber = warning;
}

abstract final class AppTypography {
  static const headline = TextStyle(
      fontSize: 28,
      fontWeight: FontWeight.w700,
      height: 1.18,
      letterSpacing: -0.5);
  static const title = TextStyle(
      fontSize: 20,
      fontWeight: FontWeight.w600,
      height: 1.25,
      letterSpacing: -0.2);
  static const body =
      TextStyle(fontSize: 16, fontWeight: FontWeight.w400, height: 1.5);
  static const label =
      TextStyle(fontSize: 14, fontWeight: FontWeight.w600, height: 1.35);
  static const caption =
      TextStyle(fontSize: 12, fontWeight: FontWeight.w400, height: 1.4);
  static const amount = TextStyle(
      fontSize: 34,
      fontWeight: FontWeight.w700,
      height: 1.1,
      letterSpacing: -0.8);
}

abstract final class AppSpacing {
  static const double xs = 4;
  static const double sm = 8;
  static const double md = 16;
  static const double lg = 24;
  static const double xl = 32;
  static const double xxl = 40;
}
