import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../auth_providers.dart';
import '../../../../core/theme/design_tokens.dart';

class SplashScreen extends ConsumerWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authProvider);

    // Trigger auth state check and redirect
    ref.listen(authProvider, (_, next) {
      if (next.isAuthenticated) {
        context.go('/home');
      } else if (next.status == AuthStateEnum.unauthenticated) {
        context.go('/login');
      }
    });

    if (auth.isAuthenticated) {
      WidgetsBinding.instance.addPostFrameCallback((_) => context.go('/home'));
    } else if (auth.status == AuthStateEnum.unauthenticated) {
      WidgetsBinding.instance.addPostFrameCallback((_) => context.go('/login'));
    } else {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        ref.read(authProvider.notifier).checkAuthState();
      });
    }

    return const Scaffold(
      backgroundColor: AppColors.onPrimary,
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Image(
              image: AssetImage('assets/branding/olekard-logo.png'),
              semanticLabel: 'OleKard',
              width: 280,
              height: 120,
              fit: BoxFit.contain,
            ),
            SizedBox(height: AppSpacing.lg),
            CircularProgressIndicator(
              valueColor: AlwaysStoppedAnimation<Color>(AppColors.primary),
            ),
          ],
        ),
      ),
    );
  }
}
