import 'package:flutter/material.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';

/// Renders a single labelled payment status row with accessibility semantics.
class PaymentStatusRow extends StatelessWidget {
  final String label;
  final String value;

  const PaymentStatusRow({
    super.key,
    required this.label,
    required this.value,
  });

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: '$label: $value',
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              label,
              style: AppTypography.bodySmall
                  .copyWith(color: AppColors.onSurfaceVariant),
            ),
            Flexible(
              child: Text(
                value,
                style: AppTypography.bodyMedium,
                textAlign: TextAlign.end,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
