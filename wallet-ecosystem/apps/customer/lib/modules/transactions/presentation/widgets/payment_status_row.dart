import 'package:flutter/material.dart';
import '../../../../../core/theme/design_tokens.dart';

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
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Expanded(
              child: Text(
                label,
                style: AppTypography.caption.copyWith(color: Colors.grey),
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Flexible(
              child: Text(
                value,
                style: AppTypography.label,
                textAlign: TextAlign.end,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
