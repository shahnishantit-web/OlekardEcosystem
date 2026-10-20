import 'package:flutter/material.dart';

abstract final class AppColors {
  static const primary = Color(0xFF1A73E8);
  static const secondary = Color(0xFF34A853);
  static const error = Color(0xFFD93025);
  static const success = Color(0xFF1E8E3E);
  static const surface = Color(0xFFFFFFFF);
  static const background = Color(0xFFF8F9FA);
  static const onPrimary = Color(0xFFFFFFFF);
  static const onSurface = Color(0xFF202124);
  static const outline = Color(0xFFDADCE0);
  static const offlineAmber = Color(0xFFF9AB00);
}

abstract final class AppTypography {
  static const headline = TextStyle(fontSize: 24, fontWeight: FontWeight.w700, height: 1.2);
  static const title = TextStyle(fontSize: 20, fontWeight: FontWeight.w600, height: 1.3);
  static const body = TextStyle(fontSize: 16, fontWeight: FontWeight.w400, height: 1.5);
  static const label = TextStyle(fontSize: 14, fontWeight: FontWeight.w500, height: 1.4);
  static const caption = TextStyle(fontSize: 12, fontWeight: FontWeight.w400, height: 1.4);
  static const amount = TextStyle(fontSize: 28, fontWeight: FontWeight.w700, height: 1.2);
}

abstract final class AppSpacing {
  static const double xs = 4;
  static const double sm = 8;
  static const double md = 16;
  static const double lg = 24;
  static const double xl = 32;
  static const double xxl = 48;
}
