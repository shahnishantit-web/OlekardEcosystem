import 'package:flutter/material.dart';
import '../domain/payment_notifier.dart';

/// T124: Full-screen status display for terminal payment states.
class PaymentStatusScreen extends StatelessWidget {
  final PaymentCheckoutState state;
  final VoidCallback? onRetry;
  final VoidCallback? onClose;

  const PaymentStatusScreen({
    super.key,
    required this.state,
    this.onRetry,
    this.onClose,
  });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Payment Status')),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: switch (state) {
            PaymentSucceeded(
              paymentIntentId: final id,
              amountMinor: final amount,
              currencyCode: final currency,
            ) =>
              _SuccessBody(
                paymentIntentId: id,
                amountMinor: amount,
                currencyCode: currency,
                onClose: onClose,
              ),
            PaymentFailed(message: final msg, canRetry: final canRetry) =>
              _FailureBody(
                  message: msg,
                  canRetry: canRetry,
                  onRetry: onRetry,
                  onClose: onClose),
            PaymentCancelled() => _SimpleBody(
                icon: Icons.cancel_outlined,
                color: Colors.grey,
                title: 'Payment Cancelled',
                message: 'The payment was cancelled.',
                onClose: onClose,
              ),
            PaymentExpired() => _SimpleBody(
                icon: Icons.timer_off_outlined,
                color: Colors.orange,
                title: 'Payment Expired',
                message: 'The payment session has expired. Please try again.',
                onClose: onClose,
              ),
            _ => _SimpleBody(
                icon: Icons.info_outline,
                color: Colors.blue,
                title: 'Processing',
                message: 'Your payment is being processed.',
                onClose: onClose,
              ),
          },
        ),
      ),
    );
  }
}

class _SuccessBody extends StatelessWidget {
  final String paymentIntentId;
  final int amountMinor;
  final String currencyCode;
  final VoidCallback? onClose;

  const _SuccessBody({
    required this.paymentIntentId,
    required this.amountMinor,
    required this.currencyCode,
    this.onClose,
  });

  @override
  Widget build(BuildContext context) {
    final whole = amountMinor ~/ 100;
    final frac = (amountMinor % 100).toString().padLeft(2, '0');
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const Icon(Icons.check_circle_outline, color: Colors.green, size: 80),
        const SizedBox(height: 16),
        const Text('Payment Successful',
            style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        Text('$currencyCode $whole.$frac',
            style: const TextStyle(fontSize: 18)),
        const SizedBox(height: 4),
        Text('Ref: $paymentIntentId',
            style: const TextStyle(fontSize: 12, color: Colors.grey)),
        const SizedBox(height: 32),
        ElevatedButton(onPressed: onClose, child: const Text('Done')),
      ],
    );
  }
}

class _FailureBody extends StatelessWidget {
  final String message;
  final bool canRetry;
  final VoidCallback? onRetry;
  final VoidCallback? onClose;

  const _FailureBody(
      {required this.message,
      required this.canRetry,
      this.onRetry,
      this.onClose});

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const Icon(Icons.error_outline, color: Colors.red, size: 80),
        const SizedBox(height: 16),
        const Text('Payment Failed',
            style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        Text(message, textAlign: TextAlign.center),
        const SizedBox(height: 32),
        if (canRetry)
          ElevatedButton(onPressed: onRetry, child: const Text('Try Again')),
        TextButton(onPressed: onClose, child: const Text('Close')),
      ],
    );
  }
}

class _SimpleBody extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String title;
  final String message;
  final VoidCallback? onClose;

  const _SimpleBody({
    required this.icon,
    required this.color,
    required this.title,
    required this.message,
    this.onClose,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, color: color, size: 80),
        const SizedBox(height: 16),
        Text(title,
            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        Text(message, textAlign: TextAlign.center),
        const SizedBox(height: 32),
        ElevatedButton(onPressed: onClose, child: const Text('Close')),
      ],
    );
  }
}
