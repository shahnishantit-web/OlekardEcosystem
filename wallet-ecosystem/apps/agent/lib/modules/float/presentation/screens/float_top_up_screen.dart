import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';

class FloatTopUpScreen extends StatelessWidget {
  const FloatTopUpScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Float funding')),
      body: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Icon(Icons.account_balance, size: 48),
            const SizedBox(height: AppSpacing.md),
            const Text(
              'Float funding is completed by an authorised administrator.',
              style: AppTypography.titleMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppSpacing.sm),
            const Text(
              'Contact your supervisor to request a top-up. This Agent app does not submit funding transactions.',
              style: AppTypography.bodyMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppSpacing.xl),
            FilledButton(
              onPressed: () => context.go('/float'),
              child: const Text('Back to Float'),
            ),
          ],
        ),
      ),
    );
  }
}
