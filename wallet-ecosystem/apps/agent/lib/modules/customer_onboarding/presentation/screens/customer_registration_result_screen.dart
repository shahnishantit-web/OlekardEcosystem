import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/success_view.dart';
import 'package:wallet_agent/modules/customer_onboarding/domain/entities/customer_profile.dart';

class CustomerRegistrationResultScreen extends StatelessWidget {
  final RegistrationResult result;
  const CustomerRegistrationResultScreen({super.key, required this.result});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Registration Complete')),
      body: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          children: [
            Expanded(
              child: SuccessView(
                referenceNumber: result.referenceNumber,
                additionalContent: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _Row(
                            label: 'Customer',
                            value: result.customerName),
                        _Row(
                            label: 'Wallet Number',
                            value: result.walletNumber),
                      ],
                    ),
                  ),
                ),
              ),
            ),
            FilledButton(
              onPressed: () => context.go('/'),
              child: const Text('Done'),
            ),
          ],
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  final String label;
  final String value;
  const _Row({required this.label, required this.value});
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label,
              style: AppTypography.bodyMedium
                  .copyWith(color: AppColors.onSurfaceVariant)),
          Text(value, style: AppTypography.bodyMedium),
        ],
      ),
    );
  }
}
