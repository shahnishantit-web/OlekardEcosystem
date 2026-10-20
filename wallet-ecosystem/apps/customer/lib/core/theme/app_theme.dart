import 'package:flutter/material.dart';
import 'design_tokens.dart';
import 'ok_theme_extension.dart';

abstract final class AppTheme {
  static ThemeData get dark => ThemeData(
        useMaterial3: true,
        brightness: Brightness.dark,
        colorScheme: const ColorScheme.dark(
          primary:          AppColors.primary,
          onPrimary:        AppColors.onPrimary,
          primaryContainer: AppColors.primaryDark,
          secondary:        AppColors.secondary,
          onSecondary:      AppColors.onPrimary,
          surface:          AppColors.surface,
          onSurface:        AppColors.onSurface,
          error:            AppColors.error,
          onError:          AppColors.onPrimary,
          outline:          AppColors.outline,
        ),
        scaffoldBackgroundColor: AppColors.background,
        appBarTheme: AppBarTheme(
          backgroundColor: AppColors.surface,
          foregroundColor: AppColors.textPrimary,
          elevation: 0,
          scrolledUnderElevation: 1,
          titleTextStyle: AppTypography.title.copyWith(
            color: AppColors.textPrimary,
          ),
        ),
        textTheme: TextTheme(
          headlineMedium: AppTypography.headline.copyWith(color: AppColors.textPrimary),
          titleMedium:    AppTypography.title.copyWith(color: AppColors.textPrimary),
          bodyMedium:     AppTypography.body.copyWith(color: AppColors.textPrimary),
          labelMedium:    AppTypography.label.copyWith(color: AppColors.textPrimary),
          bodySmall:      AppTypography.caption.copyWith(color: AppColors.textSecondary),
        ),
        inputDecorationTheme: InputDecorationTheme(
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(8),
            borderSide: const BorderSide(color: AppColors.outline),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(8),
            borderSide: const BorderSide(color: AppColors.outline),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(8),
            borderSide: const BorderSide(color: AppColors.primary, width: 2),
          ),
          filled: true,
          fillColor: AppColors.surface,
          contentPadding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.md,
            vertical: AppSpacing.sm,
          ),
          hintStyle: const TextStyle(color: AppColors.textSecondary),
        ),
        elevatedButtonTheme: ElevatedButtonThemeData(
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.primary,
            foregroundColor: AppColors.onPrimary,
            minimumSize: const Size.fromHeight(48),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            textStyle: AppTypography.label,
          ).copyWith(
            overlayColor: WidgetStateProperty.resolveWith((states) {
              if (states.contains(WidgetState.focused)) {
                return AppColors.primaryLight.withValues(alpha: 0.3);
              }
              return null;
            }),
          ),
        ),
        cardTheme: CardThemeData(
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
            side: const BorderSide(color: AppColors.outline),
          ),
          color: AppColors.surface,
        ),
        navigationBarTheme: NavigationBarThemeData(
          backgroundColor: AppColors.surface,
          indicatorColor: AppColors.primary,
          labelTextStyle: WidgetStateProperty.resolveWith((states) {
            if (states.contains(WidgetState.selected)) {
              return AppTypography.label.copyWith(color: AppColors.primary);
            }
            return AppTypography.label.copyWith(color: AppColors.textSecondary);
          }),
          iconTheme: WidgetStateProperty.resolveWith((states) {
            if (states.contains(WidgetState.selected)) {
              return const IconThemeData(color: AppColors.primary);
            }
            return const IconThemeData(color: AppColors.textSecondary);
          }),
        ),
        extensions: [OkThemeExtension.consumer()],
      );

  // Kept for backward compatibility; app should use [dark].
  static ThemeData get light => dark;
}
