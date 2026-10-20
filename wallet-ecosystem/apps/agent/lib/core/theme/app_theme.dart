import 'package:flutter/material.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/core/theme/ok_theme_extension.dart';

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
          titleTextStyle: AppTypography.headlineMedium.copyWith(
            color: AppColors.textPrimary,
          ),
        ),
        cardTheme: CardThemeData(
          elevation: AppSpacing.cardElevation,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppSpacing.borderRadius),
          ),
          color: AppColors.surface,
        ),
        elevatedButtonTheme: ElevatedButtonThemeData(
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.primary,
            foregroundColor: AppColors.onPrimary,
            minimumSize: const Size.fromHeight(AppSpacing.minTouchTarget),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(AppSpacing.borderRadius),
            ),
            textStyle: AppTypography.labelLarge,
          ).copyWith(
            overlayColor: WidgetStateProperty.resolveWith((states) {
              if (states.contains(WidgetState.focused)) {
                return AppColors.primaryLight.withValues(alpha: 0.3);
              }
              return null;
            }),
          ),
        ),
        outlinedButtonTheme: OutlinedButtonThemeData(
          style: OutlinedButton.styleFrom(
            foregroundColor: AppColors.primary,
            minimumSize: const Size.fromHeight(AppSpacing.minTouchTarget),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(AppSpacing.borderRadius),
            ),
            side: const BorderSide(color: AppColors.primary),
            textStyle: AppTypography.labelLarge,
          ),
        ),
        textButtonTheme: TextButtonThemeData(
          style: TextButton.styleFrom(
            foregroundColor: AppColors.primary,
            minimumSize: const Size(AppSpacing.minTouchTarget, AppSpacing.minTouchTarget),
            textStyle: AppTypography.labelLarge,
          ),
        ),
        inputDecorationTheme: InputDecorationTheme(
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(AppSpacing.borderRadiusSm),
            borderSide: const BorderSide(color: AppColors.outline),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(AppSpacing.borderRadiusSm),
            borderSide: const BorderSide(color: AppColors.outline),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(AppSpacing.borderRadiusSm),
            borderSide: const BorderSide(color: AppColors.primary, width: 2),
          ),
          contentPadding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.md,
            vertical: AppSpacing.md,
          ),
          filled: true,
          fillColor: AppColors.surface,
          hintStyle: const TextStyle(color: AppColors.textSecondary),
        ),
        bottomNavigationBarTheme: const BottomNavigationBarThemeData(
          backgroundColor: AppColors.surface,
          selectedItemColor: AppColors.primary,
          unselectedItemColor: AppColors.onSurfaceVariant,
          type: BottomNavigationBarType.fixed,
          elevation: 8,
        ),
        chipTheme: ChipThemeData(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppSpacing.borderRadius),
          ),
        ),
        dividerTheme: const DividerThemeData(
          color: AppColors.divider,
          thickness: 1,
        ),
        textTheme: TextTheme(
          displayLarge:   AppTypography.displayLarge.copyWith(color: AppColors.textPrimary),
          displayMedium:  AppTypography.displayMedium.copyWith(color: AppColors.textPrimary),
          headlineLarge:  AppTypography.headlineLarge.copyWith(color: AppColors.textPrimary),
          headlineMedium: AppTypography.headlineMedium.copyWith(color: AppColors.textPrimary),
          titleLarge:     AppTypography.titleLarge.copyWith(color: AppColors.textPrimary),
          titleMedium:    AppTypography.titleMedium.copyWith(color: AppColors.textPrimary),
          bodyLarge:      AppTypography.bodyLarge.copyWith(color: AppColors.textPrimary),
          bodyMedium:     AppTypography.bodyMedium.copyWith(color: AppColors.textPrimary),
          bodySmall:      AppTypography.bodySmall.copyWith(color: AppColors.textSecondary),
          labelLarge:     AppTypography.labelLarge.copyWith(color: AppColors.textPrimary),
          labelMedium:    AppTypography.labelMedium.copyWith(color: AppColors.textSecondary),
        ),
        navigationBarTheme: NavigationBarThemeData(
          backgroundColor: AppColors.surface,
          indicatorColor: AppColors.primary,
          labelTextStyle: WidgetStateProperty.resolveWith((states) {
            if (states.contains(WidgetState.selected)) {
              return AppTypography.labelLarge.copyWith(color: AppColors.primary);
            }
            return AppTypography.labelLarge.copyWith(color: AppColors.onSurfaceVariant);
          }),
          iconTheme: WidgetStateProperty.resolveWith((states) {
            if (states.contains(WidgetState.selected)) {
              return const IconThemeData(color: AppColors.primary);
            }
            return const IconThemeData(color: AppColors.onSurfaceVariant);
          }),
        ),
        extensions: [OkThemeExtension.agent()],
      );

  // Kept for backward compatibility; app always uses dark theme.
  static ThemeData get light => dark;
}
