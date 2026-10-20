import 'package:flutter/material.dart';

/// T123: Summary widget showing purpose description, recipient, base amount,
/// disclosed fees, and total amount.
class PaymentAmountSummary extends StatelessWidget {
  final String purposeDescription;
  final String recipient;
  final int baseAmountMinor;
  final int feeMinor;
  final String currencyCode;

  const PaymentAmountSummary({
    super.key,
    required this.purposeDescription,
    required this.recipient,
    required this.baseAmountMinor,
    required this.feeMinor,
    required this.currencyCode,
  });

  int get totalAmountMinor => baseAmountMinor + feeMinor;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          purposeDescription,
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 4),
        Text('To: $recipient', style: Theme.of(context).textTheme.bodySmall),
        const Divider(height: 24),
        _row('Amount', baseAmountMinor),
        if (feeMinor > 0) _row('Service Fee', feeMinor),
        const Divider(height: 16),
        _row('Total', totalAmountMinor, bold: true),
      ],
    );
  }

  Widget _row(String label, int minor, {bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label,
              style:
                  bold ? const TextStyle(fontWeight: FontWeight.bold) : null),
          Text(
            _formatMinor(minor, currencyCode),
            style: bold ? const TextStyle(fontWeight: FontWeight.bold) : null,
          ),
        ],
      ),
    );
  }

  String _formatMinor(int minor, String currency) {
    final whole = minor ~/ 100;
    final frac = (minor % 100).toString().padLeft(2, '0');
    return '$currency $whole.$frac';
  }
}
