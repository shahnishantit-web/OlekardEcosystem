import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';

class SplashScreen extends ConsumerWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return const Scaffold(
      backgroundColor: AppColors.onPrimary,
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Image(
              image: AssetImage('assets/branding/olekard-logo.png'),
              semanticLabel: 'OleKard',
              width: 280,
              height: 120,
              fit: BoxFit.contain,
            ),
            SizedBox(height: AppSpacing.md),
            Text(
              'Agent Portal',
              style: TextStyle(
                color: AppColors.primaryDark,
                fontSize: 28,
                fontWeight: FontWeight.bold,
              ),
            ),
            SizedBox(height: AppSpacing.xl),
            CircularProgressIndicator(
              color: AppColors.primary,
            ),
          ],
        ),
      ),
    );
  }
}
