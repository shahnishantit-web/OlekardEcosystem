import 'package:flutter/material.dart';
import '../../domain/models/payment_option.dart';

class WalletBalanceTile extends StatelessWidget {
  final PaymentOption option;

  const WalletBalanceTile({super.key, required this.option});

  @override
  Widget build(BuildContext context) {
    final balance  = option.walletBalanceMinor ?? 0;
    final required = option.feeDisclosure?.totalPayableMinor;
    final sufficient = option.hasSufficientBalance ?? true;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            const Icon(Icons.account_balance_wallet, size: 32),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Wallet Balance: ${_fmt(balance, option.providerCode)}',
                      style: const TextStyle(fontWeight: FontWeight.bold)),
                  if (!sufficient && required != null)
                    Text(
                      'Insufficient balance · Need ${_fmt(required, option.providerCode)}',
                      style: const TextStyle(color: Colors.red, fontSize: 12),
                    ),
                  if (!sufficient && required == null)
                    const Text('Insufficient balance',
                        style: TextStyle(color: Colors.red, fontSize: 12)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  static String _fmt(int minor, String _) {
    final whole = minor ~/ 100;
    final frac  = (minor % 100).toString().padLeft(2, '0');
    return '$whole.$frac';
  }
}
