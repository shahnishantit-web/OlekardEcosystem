import 'package:flutter/material.dart';
import '../domain/checkout_state.dart';

class PaymentStatusScreen extends StatelessWidget {
  final PaymentCheckoutState state;
  final VoidCallback onClose;
  final VoidCallback? onRetry;

  const PaymentStatusScreen({
    super.key,
    required this.state,
    required this.onClose,
    this.onRetry,
  });

  @override
  Widget build(BuildContext context) {
    return switch (state) {
      PaymentSucceeded(
        paymentIntentId: final id,
        amountMinor: final amt,
        currencyCode: final cc,
      ) =>
        _StatusBody(
          icon: Icons.check_circle_outline,
          iconColor: Colors.green,
          title: 'Payment Successful',
          subtitle: '${_fmt(amt, cc)} paid · Ref: $id',
          onClose: onClose,
        ),
      PaymentFailed(message: final msg, canRetry: final retry) =>
        _StatusBody(
          icon: Icons.error_outline,
          iconColor: Colors.red,
          title: 'Payment Failed',
          subtitle: msg,
          onClose: onClose,
          onRetry: retry ? onRetry : null,
        ),
      PaymentCancelled() => _StatusBody(
          icon: Icons.cancel_outlined,
          iconColor: Colors.orange,
          title: 'Payment Cancelled',
          subtitle: 'The payment was cancelled.',
          onClose: onClose,
        ),
      PaymentExpired() => _StatusBody(
          icon: Icons.timer_off_outlined,
          iconColor: Colors.grey,
          title: 'Payment Expired',
          subtitle: 'The payment session expired.',
          onClose: onClose,
        ),
      _ => _StatusBody(
          icon: Icons.help_outline,
          iconColor: Colors.grey,
          title: 'Payment Status Unknown',
          subtitle: 'Please contact support.',
          onClose: onClose,
        ),
    };
  }

  static String _fmt(int minor, String cc) {
    final whole = minor ~/ 100;
    final frac  = (minor % 100).toString().padLeft(2, '0');
    return '$cc $whole.$frac';
  }
}

class _StatusBody extends StatelessWidget {
  final IconData icon;
  final Color iconColor;
  final String title;
  final String subtitle;
  final VoidCallback onClose;
  final VoidCallback? onRetry;

  const _StatusBody({
    required this.icon,
    required this.iconColor,
    required this.title,
    required this.subtitle,
    required this.onClose,
    this.onRetry,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 64, color: iconColor),
            const SizedBox(height: 16),
            Text(title,
                style:
                    const TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Text(subtitle, textAlign: TextAlign.center),
            const SizedBox(height: 24),
            if (onRetry != null) ...[
              ElevatedButton(
                  onPressed: onRetry, child: const Text('Try Again')),
              const SizedBox(height: 8),
            ],
            TextButton(onPressed: onClose, child: const Text('Close')),
          ],
        ),
      ),
    );
  }
}
