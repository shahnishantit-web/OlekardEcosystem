import 'package:flutter/material.dart';
import '../../domain/models/payment_option.dart';

/// T122: Tile showing wallet balance for the OleKard Wallet option.
/// Balance comes from the API response only — never from local wallet state.
class WalletBalanceTile extends StatelessWidget {
  final PaymentOption option;

  const WalletBalanceTile({super.key, required this.option});

  @override
  Widget build(BuildContext context) {
    if (!option.isWallet) return const SizedBox.shrink();

    final balance      = option.walletBalanceMinor ?? 0;
    final hasSufficient = option.hasSufficientBalance ?? false;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: hasSufficient ? Colors.green.shade50 : Colors.red.shade50,
        border: Border.all(
          color: hasSufficient ? Colors.green.shade200 : Colors.red.shade200,
        ),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            'Available Balance',
            style: TextStyle(
              color: hasSufficient ? Colors.green.shade800 : Colors.red.shade800,
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                _formatMinor(balance),
                style: TextStyle(
                  fontWeight: FontWeight.bold,
                  color: hasSufficient ? Colors.green.shade800 : Colors.red.shade800,
                ),
              ),
              if (!hasSufficient)
                const Text(
                  'Insufficient Balance',
                  style: TextStyle(fontSize: 11, color: Colors.red),
                ),
            ],
          ),
        ],
      ),
    );
  }

  String _formatMinor(int minor) {
    final whole = minor ~/ 100;
    final frac  = (minor % 100).toString().padLeft(2, '0');
    return 'BWP $whole.$frac';
  }
}
