import 'package:flutter/material.dart';
import '../../domain/models/payment_option.dart';

class PaymentOptionTile extends StatelessWidget {
  final PaymentOption option;
  final bool isSelected;
  final VoidCallback? onTap;

  const PaymentOptionTile({
    super.key,
    required this.option,
    this.isSelected = false,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final isDisabled =
        option.isRestricted || (option.isWallet && option.hasSufficientBalance == false);

    return ListTile(
      enabled: !isDisabled,
      leading: option.iconUrl != null
          ? Image.network(option.iconUrl!, width: 32, height: 32)
          : const Icon(Icons.payment),
      title: Row(
        children: [
          Flexible(
            child: Text(
              option.effectiveLabel,
              style: TextStyle(color: isDisabled ? Colors.grey : null),
            ),
          ),
          if (option.isDefault) ...[
            const SizedBox(width: 8),
            _Chip(label: 'DEFAULT', color: Colors.blue),
          ],
          if (isDisabled && option.isRestricted) ...[
            const SizedBox(width: 8),
            _Chip(label: 'UNAVAILABLE', color: Colors.red),
          ],
          if (isDisabled && !option.isRestricted) ...[
            const SizedBox(width: 8),
            _Chip(label: 'INSUFFICIENT BALANCE', color: Colors.red),
          ],
        ],
      ),
      subtitle: _buildSubtitle(isDisabled),
      trailing: isSelected
          ? const Icon(Icons.check_circle, color: Colors.green)
          : const Icon(Icons.radio_button_unchecked),
      onTap: isDisabled ? null : onTap,
    );
  }

  Widget? _buildSubtitle(bool isDisabled) {
    final fee    = option.feeDisclosure;
    final parts  = <String>[];

    if (option.isRestricted) {
      parts.addAll(option.restrictions);
    } else {
      if (fee?.totalFeeMinor != null && fee!.totalFeeMinor! > 0) {
        parts.add('Fee: ${(fee.totalFeeMinor! / 100).toStringAsFixed(2)}');
      }
      if (fee?.totalPayableMinor != null) {
        parts.add('Total: ${(fee!.totalPayableMinor! / 100).toStringAsFixed(2)}');
      }
    }

    if (parts.isEmpty) return null;
    return Text(parts.join('  ·  '),
        style: TextStyle(
            fontSize: 12,
            color: isDisabled ? Colors.grey.shade400 : Colors.grey.shade600));
  }
}

class _Chip extends StatelessWidget {
  final String label;
  final Color color;

  const _Chip({required this.label, required this.color});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
      decoration: BoxDecoration(
        color:        color.withOpacity(0.1),
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(label,
          style: TextStyle(fontSize: 10, color: color)),
    );
  }
}
