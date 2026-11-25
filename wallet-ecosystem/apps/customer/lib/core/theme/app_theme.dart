import 'package:flutter/material.dart';
import 'design_tokens.dart';
import 'ok_theme_extension.dart';

abstract final class AppTheme {
  static ThemeData get light {
    final scheme = ColorScheme.fromSeed(
      seedColor: AppColors.primary,
      brightness: Brightness.light,
      primary: AppColors.primary,
      onPrimary: AppColors.onPrimary,
      secondary: AppColors.secondary,
      surface: AppColors.surface,
      onSurface: AppColors.textPrimary,
      error: AppColors.error,
    );
    final text = ThemeData.light().textTheme;
    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.light,
      colorScheme: scheme,
      scaffoldBackgroundColor: AppColors.background,
      visualDensity: VisualDensity.standard,
      appBarTheme: AppBarTheme(
        backgroundColor: AppColors.background,
        foregroundColor: AppColors.textPrimary,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle:
            AppTypography.title.copyWith(color: AppColors.textPrimary),
      ),
      textTheme: text.copyWith(
        headlineMedium:
            AppTypography.headline.copyWith(color: AppColors.textPrimary),
        headlineSmall: AppTypography.headline
            .copyWith(fontSize: 25, color: AppColors.textPrimary),
        titleLarge: AppTypography.title.copyWith(color: AppColors.textPrimary),
        titleMedium: AppTypography.label
            .copyWith(fontSize: 16, color: AppColors.textPrimary),
        titleSmall: AppTypography.label.copyWith(color: AppColors.textPrimary),
        bodyLarge: AppTypography.body.copyWith(color: AppColors.textPrimary),
        bodyMedium: AppTypography.body
            .copyWith(fontSize: 14, color: AppColors.textPrimary),
        bodySmall:
            AppTypography.caption.copyWith(color: AppColors.textSecondary),
        labelLarge: AppTypography.label.copyWith(color: AppColors.textPrimary),
        labelMedium: AppTypography.label
            .copyWith(fontSize: 12, color: AppColors.textSecondary),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.surface,
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 16, vertical: 17),
        border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: AppColors.outline)),
        enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: AppColors.outline)),
        focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: AppColors.primary, width: 1.6)),
        errorBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: AppColors.error)),
        focusedErrorBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: AppColors.error, width: 1.6)),
        labelStyle:
            AppTypography.label.copyWith(color: AppColors.textSecondary),
        hintStyle: AppTypography.body
            .copyWith(color: AppColors.textSecondary.withValues(alpha: .75)),
        prefixIconColor: AppColors.textSecondary,
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(style: _buttonStyle()),
      filledButtonTheme: FilledButtonThemeData(style: _buttonStyle()),
      outlinedButtonTheme: OutlinedButtonThemeData(
          style: OutlinedButton.styleFrom(
        minimumSize: const Size(48, 50),
        foregroundColor: AppColors.primary,
        side: const BorderSide(color: AppColors.outline),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        textStyle: AppTypography.label,
      )),
      cardTheme: CardThemeData(
        color: AppColors.surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
            side: const BorderSide(color: AppColors.outline)),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: AppColors.surface,
        elevation: 0,
        indicatorColor: AppColors.primaryLight,
        labelTextStyle: WidgetStateProperty.resolveWith(
            (states) => AppTypography.label.copyWith(
                  fontSize: 12,
                  color: states.contains(WidgetState.selected)
                      ? AppColors.primary
                      : AppColors.textSecondary,
                )),
        iconTheme: WidgetStateProperty.resolveWith((states) => IconThemeData(
              color: states.contains(WidgetState.selected)
                  ? AppColors.primary
                  : AppColors.textSecondary,
            )),
      ),
      dividerTheme: const DividerThemeData(
          color: AppColors.outline, thickness: 1, space: 1),
      extensions: [OkThemeExtension.consumer()],
    );
  }

  static ButtonStyle _buttonStyle() => FilledButton.styleFrom(
        backgroundColor: AppColors.primary,
        foregroundColor: AppColors.onPrimary,
        disabledBackgroundColor: const Color(0xFFCBD5E1),
        disabledForegroundColor: const Color(0xFF64748B),
        minimumSize: const Size(48, 52),
        padding: const EdgeInsets.symmetric(horizontal: 20),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        textStyle: AppTypography.label.copyWith(fontSize: 15),
      );

  /// Retained for legacy screens that explicitly request a dark theme.
  static ThemeData get dark => ThemeData.dark(useMaterial3: true).copyWith(
        colorScheme: ColorScheme.fromSeed(
            seedColor: AppColors.primary, brightness: Brightness.dark),
        extensions: [OkThemeExtension.consumer()],
      );
}
