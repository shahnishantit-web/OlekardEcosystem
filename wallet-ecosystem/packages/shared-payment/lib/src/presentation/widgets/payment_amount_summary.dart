import 'package:flutter/material.dart';
import '../../domain/models/payment_option.dart';

class PaymentAmountSummary extends StatelessWidget {
  final PaymentOption option;

  const PaymentAmountSummary({super.key, required this.option});

  @override
  Widget build(BuildContext context) {
    final fee = option.feeDisclosure;
    if (fee == null) return const SizedBox.shrink();

    return Card(
      margin: const EdgeInsets.symmetric(vertical: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text('Payment Summary',
                style: TextStyle(fontWeight: FontWeight.bold)),
            const Divider(),
            if (fee.serviceFeeMinor != null && fee.serviceFeeMinor! > 0)
              _Row(
                  'Service fee',
                  _fmt(fee.serviceFeeMinor!)),
            if (fee.providerFeeMinor != null && fee.providerFeeMinor! > 0)
              _Row(
                  'Provider fee',
                  _fmt(fee.providerFeeMinor!)),
            if (fee.totalPayableMinor != null) ...[
              const Divider(),
              _Row(
                'Total payable',
                _fmt(fee.totalPayableMinor!),
                bold: true,
              ),
            ],
          ],
        ),
      ),
    );
  }

  static String _fmt(int minor) {
    final whole = minor ~/ 100;
    final frac  = (minor % 100).toString().padLeft(2, '0');
    return '$whole.$frac';
  }
}

class _Row extends StatelessWidget {
  final String label;
  final String value;
  final bool bold;

  const _Row(this.label, this.value, {this.bold = false});

  @override
  Widget build(BuildContext context) {
    final style = bold
        ? const TextStyle(fontWeight: FontWeight.bold)
        : null;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: style),
          Text(value,  style: style),
        ],
      ),
    );
  }
}
