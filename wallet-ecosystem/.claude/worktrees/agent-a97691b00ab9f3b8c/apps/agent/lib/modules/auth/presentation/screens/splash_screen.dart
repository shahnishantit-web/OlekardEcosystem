import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/modules/auth/presentation/auth_providers.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';

class SplashScreen extends ConsumerWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.listen<AuthState>(authNotifierProvider, (_, next) {
      if (next.status == AuthStateEnum.authenticated) {
        context.go('/dashboard');
      } else if (next.status == AuthStateEnum.unauthenticated ||
          next.status == AuthStateEnum.requiresReauth) {
        context.go('/login');
      }
    });

    return const Scaffold(
      backgroundColor: AppColors.primary,
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.account_balance_wallet, size: 80, color: Colors.white),
            SizedBox(height: AppSpacing.md),
            Text(
              'OleKard Agent',
              style: TextStyle(
                color: Colors.white,
                fontSize: 28,
                fontWeight: FontWeight.bold,
              ),
            ),
            SizedBox(height: AppSpacing.xl),
            CircularProgressIndicator(
              color: Colors.white70,
            ),
          ],
        ),
      ),
    );
  }
}
