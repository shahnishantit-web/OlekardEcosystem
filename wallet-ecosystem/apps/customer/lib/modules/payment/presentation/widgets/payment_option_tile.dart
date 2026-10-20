import 'package:flutter/material.dart';
import '../../domain/models/payment_option.dart';

/// T121: Tile widget for a single PaymentOption in the checkout list.
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

  bool get _isDisabled =>
      option.isWallet == true && option.hasSufficientBalance == false;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      enabled: !_isDisabled,
      leading: option.iconUrl != null
          ? Image.network(option.iconUrl!, width: 32, height: 32)
          : const Icon(Icons.payment),
      title: Row(
        children: [
          Text(
            option.displayName,
            style: TextStyle(
              color: _isDisabled ? Colors.grey : null,
            ),
          ),
          if (option.isDefault) ...[
            const SizedBox(width: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
              decoration: BoxDecoration(
                color: Colors.blue.shade100,
                borderRadius: BorderRadius.circular(4),
              ),
              child: const Text(
                'DEFAULT',
                style: TextStyle(fontSize: 10, color: Colors.blue),
              ),
            ),
          ],
          if (_isDisabled) ...[
            const SizedBox(width: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
              decoration: BoxDecoration(
                color: Colors.red.shade100,
                borderRadius: BorderRadius.circular(4),
              ),
              child: const Text(
                'INSUFFICIENT BALANCE',
                style: TextStyle(fontSize: 10, color: Colors.red),
              ),
            ),
          ],
        ],
      ),
      trailing: isSelected
          ? const Icon(Icons.check_circle, color: Colors.green)
          : const Icon(Icons.radio_button_unchecked),
      onTap: _isDisabled ? null : onTap,
    );
  }
}
