export 'package:design_system/design_system.dart' show OkSuccessView;

import 'package:flutter/material.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';

class SuccessView extends StatelessWidget {
  final String referenceNumber;
  final String? successMessage;
  final VoidCallback? onShareReceipt;
  final VoidCallback? onDone;
  final String doneLabel;
  final Widget? additionalContent;

  const SuccessView({
    super.key,
    required this.referenceNumber,
    this.successMessage,
    this.onShareReceipt,
    this.onDone,
    this.doneLabel = 'Done',
    this.additionalContent,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const Icon(Icons.check_circle_outline, size: 80, color: AppColors.success),
          const SizedBox(height: AppSpacing.md),
          if (successMessage != null) ...[
            Text(successMessage!, style: AppTypography.headlineMedium, textAlign: TextAlign.center),
            const SizedBox(height: AppSpacing.lg),
          ],
          Container(
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg, vertical: AppSpacing.md),
            decoration: BoxDecoration(
              color: AppColors.successLight,
              borderRadius: BorderRadius.circular(AppSpacing.borderRadius),
            ),
            child: Column(
              children: [
                Text('Reference Number',
                    style: AppTypography.bodySmall.copyWith(color: AppColors.onSurfaceVariant)),
                const SizedBox(height: AppSpacing.xs),
                Text(referenceNumber,
                    style: AppTypography.titleLarge.copyWith(color: AppColors.success, letterSpacing: 1),
                    semanticsLabel: 'Reference number: $referenceNumber'),
              ],
            ),
          ),
          if (additionalContent != null) ...[
            const SizedBox(height: AppSpacing.md),
            additionalContent!,
          ],
          const SizedBox(height: AppSpacing.xl),
          if (onShareReceipt != null) ...[
            OutlinedButton.icon(
              onPressed: onShareReceipt,
              icon: const Icon(Icons.share_outlined),
              label: const Text('Share Receipt'),
              style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(AppSpacing.minTouchTarget)),
            ),
            const SizedBox(height: AppSpacing.md),
          ],
          if (onDone != null)
            ElevatedButton(
              onPressed: onDone,
              style: ElevatedButton.styleFrom(minimumSize: const Size.fromHeight(AppSpacing.minTouchTarget)),
              child: Text(doneLabel),
            ),
        ],
      ),
    );
  }
}
