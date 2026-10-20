import 'package:flutter/material.dart';
import '../../core/theme/design_tokens.dart';
import 'primary_button.dart';

class SuccessView extends StatelessWidget {
  final String title;
  final String referenceNumber;
  final String? actionLabel;
  final VoidCallback? onAction;

  const SuccessView({
    super.key,
    required this.title,
    required this.referenceNumber,
    this.actionLabel,
    this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.check_circle_outline, size: 72, color: AppColors.success),
            const SizedBox(height: AppSpacing.md),
            Text(title, style: AppTypography.headline, textAlign: TextAlign.center),
            const SizedBox(height: AppSpacing.sm),
            Chip(
              label: Text(
                referenceNumber,
                style: AppTypography.label.copyWith(color: AppColors.primary),
              ),
              backgroundColor: AppColors.background,
            ),
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: AppSpacing.lg),
              PrimaryButton(label: actionLabel!, onPressed: onAction),
            ],
          ],
        ),
      ),
    );
  }
}
