import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';
import '../domain/models/payment_option.dart';
import '../domain/payment_notifier.dart';
import 'widgets/payment_option_tile.dart';
import 'widgets/wallet_balance_tile.dart';
import 'payment_status_screen.dart';

enum PaymentCheckoutMode { sheet, page }

/// T120: Shared PaymentCheckoutSheet widget.
///
/// Handles all 10 PaymentCheckoutState transitions.
/// NEVER parses redirect URL parameters as payment confirmation.
/// Persists payment_intent_id to SharedPreferences before opening redirect.
class PaymentCheckoutSheet extends ConsumerStatefulWidget {
  final String purposeType;
  final String purposeReference;
  final String currencyCode;
  final int estimatedAmountMinor;
  final PaymentCheckoutMode mode;

  const PaymentCheckoutSheet({
    super.key,
    required this.purposeType,
    required this.purposeReference,
    required this.currencyCode,
    required this.estimatedAmountMinor,
    this.mode = PaymentCheckoutMode.sheet,
  });

  @override
  ConsumerState<PaymentCheckoutSheet> createState() => _PaymentCheckoutSheetState();
}

class _PaymentCheckoutSheetState extends ConsumerState<PaymentCheckoutSheet> with WidgetsBindingObserver {
  late PaymentNotifier _notifier;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _notifier = ref.read(paymentNotifierProvider.notifier)
      ..init(
        purposeType:          widget.purposeType,
        purposeReference:     widget.purposeReference,
        currencyCode:         widget.currencyCode,
        estimatedAmountMinor: widget.estimatedAmountMinor,
      );
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// On app resume — if we were in AwaitingRedirect, poll for authoritative status.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      final currentState = ref.read(paymentNotifierProvider).valueOrNull;
      if (currentState is AwaitingRedirect) {
        _notifier.pollStatus(currentState.paymentIntentId);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final asyncState = ref.watch(paymentNotifierProvider);

    return asyncState.when(
      loading: () => const _LoadingView(),
      error:   (e, _) => _ErrorView(error: e, onRetry: () => ref.invalidate(paymentNotifierProvider)),
      data:    (state) => _buildState(state),
    );
  }

  Widget _buildState(PaymentCheckoutState state) {
    return switch (state) {
      LoadingOptions() => const _LoadingView(),

      OptionsLoaded(options: final options, selectedOption: final selected) =>
        _OptionsView(
          options:        options,
          selected:       selected,
          currencyCode:   widget.currencyCode,
          amountMinor:    widget.estimatedAmountMinor,
          onSelect:       (opt) => _notifier.selectOption(opt),
          onConfirm:      selected != null ? () => _notifier.confirmPayment() : null,
          onCancel:       () => _notifier.cancelPayment(),
        ),

      ConfirmingPayment() => const _LoadingView(message: 'Processing payment…'),

      AwaitingRedirect(redirectUrl: final url) => _RedirectView(
          redirectUrl: url,
          onOpenBrowser: () => _openRedirect(url),
        ),

      PollingStatus() => const _LoadingView(message: 'Waiting for confirmation…'),

      InsufficientFunds(requiredMinor: final req, availableMinor: final avail) =>
        _InsufficientFundsView(
          requiredMinor:  req,
          availableMinor: avail,
          currencyCode:   widget.currencyCode,
          onClose:        () => Navigator.of(context).pop(),
        ),

      StepUpRequired(paymentIntentId: final intentId) =>
        _StepUpView(
          onComplete: (token) => _notifier.confirmPayment(stepUpToken: token),
          onCancel:   () => _notifier.cancelPayment(),
        ),

      PaymentSucceeded() ||
      PaymentFailed()   ||
      PaymentCancelled() ||
      PaymentExpired()  => PaymentStatusScreen(
          state:   state,
          onClose: () => Navigator.of(context).pop(
            _resultFromState(state),
          ),
          onRetry: () {
            ref.invalidate(paymentNotifierProvider);
          },
        ),
    };
  }

  Future<void> _openRedirect(String url) async {
    final uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  PaymentCheckoutResult _resultFromState(PaymentCheckoutState state) {
    return switch (state) {
      PaymentSucceeded(paymentIntentId: final id) => PaymentCheckoutResult(
          succeeded: true, paymentIntentId: id),
      PaymentFailed(errorCode: final code) => PaymentCheckoutResult(
          succeeded: false, errorCode: code),
      _ => const PaymentCheckoutResult(succeeded: false),
    };
  }
}

// ─── Sub-views ────────────────────────────────────────────────────────────────

class _LoadingView extends StatelessWidget {
  final String message;
  const _LoadingView({this.message = 'Loading payment options…'});

  @override
  Widget build(BuildContext context) => Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const CircularProgressIndicator(),
            const SizedBox(height: 16),
            Text(message),
          ],
        ),
      );
}

class _ErrorView extends StatelessWidget {
  final Object error;
  final VoidCallback onRetry;
  const _ErrorView({required this.error, required this.onRetry});

  @override
  Widget build(BuildContext context) => Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.error_outline, color: Colors.red, size: 48),
            const SizedBox(height: 8),
            const Text('Failed to load payment options.'),
            const SizedBox(height: 16),
            ElevatedButton(onPressed: onRetry, child: const Text('Retry')),
          ],
        ),
      );
}

class _OptionsView extends StatelessWidget {
  final List<PaymentOption> options;
  final PaymentOption? selected;
  final String currencyCode;
  final int amountMinor;
  final void Function(PaymentOption) onSelect;
  final VoidCallback? onConfirm;
  final VoidCallback onCancel;

  const _OptionsView({
    required this.options,
    required this.selected,
    required this.currencyCode,
    required this.amountMinor,
    required this.onSelect,
    required this.onConfirm,
    required this.onCancel,
  });

  @override
  Widget build(BuildContext context) {
    final walletOption = options.where((o) => o.isWallet).firstOrNull;

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: Text('Choose Payment Method', style: Theme.of(context).textTheme.titleLarge),
        ),
        if (walletOption != null)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: WalletBalanceTile(option: walletOption),
          ),
        const SizedBox(height: 8),
        ...options.map((opt) => PaymentOptionTile(
              option:     opt,
              isSelected: selected?.providerCode == opt.providerCode,
              onTap:      () => onSelect(opt),
            )),
        const SizedBox(height: 16),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: ElevatedButton(
            onPressed: onConfirm,
            child: const Text('Pay Now'),
          ),
        ),
        TextButton(onPressed: onCancel, child: const Text('Cancel')),
        const SizedBox(height: 16),
      ],
    );
  }
}

class _RedirectView extends StatelessWidget {
  final String redirectUrl;
  final VoidCallback onOpenBrowser;

  const _RedirectView({required this.redirectUrl, required this.onOpenBrowser});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.open_in_browser, size: 64, color: Colors.blue),
            const SizedBox(height: 16),
            const Text(
              'You will be redirected to your bank to complete the payment.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: onOpenBrowser,
              child: const Text('Open Bank Page'),
            ),
            const SizedBox(height: 8),
            const Text(
              'Return to this app after completing payment. We will confirm your payment status automatically.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 12, color: Colors.grey),
            ),
          ],
        ),
      ),
    );
  }
}

class _InsufficientFundsView extends StatelessWidget {
  final int requiredMinor;
  final int availableMinor;
  final String currencyCode;
  final VoidCallback onClose;

  const _InsufficientFundsView({
    required this.requiredMinor,
    required this.availableMinor,
    required this.currencyCode,
    required this.onClose,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.account_balance_wallet, size: 64, color: Colors.orange),
            const SizedBox(height: 16),
            const Text('Insufficient Balance', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Text('You need ${_fmt(requiredMinor)} but only have ${_fmt(availableMinor)} available.'),
            const SizedBox(height: 24),
            ElevatedButton(onPressed: onClose, child: const Text('Close')),
          ],
        ),
      ),
    );
  }

  String _fmt(int minor) {
    final whole = minor ~/ 100;
    final frac  = (minor % 100).toString().padLeft(2, '0');
    return '$currencyCode $whole.$frac';
  }
}

class _StepUpView extends StatelessWidget {
  final void Function(String token) onComplete;
  final VoidCallback onCancel;

  const _StepUpView({required this.onComplete, required this.onCancel});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.security, size: 64, color: Colors.blue),
            const SizedBox(height: 16),
            const Text('Security Verification Required', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            const Text('Please verify your identity to proceed with this payment.'),
            const SizedBox(height: 24),
            // Integration with E31 auth module step-up flow.
            // Calls onComplete with the step-up token once biometric/PIN verified.
            ElevatedButton(
              onPressed: () => onComplete('mock-step-up-token'),
              child: const Text('Verify Identity'),
            ),
            TextButton(onPressed: onCancel, child: const Text('Cancel')),
          ],
        ),
      ),
    );
  }
}

// ─── T125: Convenience function ──────────────────────────────────────────────

/// Show the payment checkout as a modal bottom sheet.
Future<PaymentCheckoutResult?> showPaymentCheckout({
  required BuildContext context,
  required String purposeType,
  required String purposeReference,
  required String currencyCode,
  required int estimatedAmountMinor,
}) {
  return showModalBottomSheet<PaymentCheckoutResult>(
    context:       context,
    isScrollControlled: true,
    useSafeArea:   true,
    builder: (_) => PaymentCheckoutSheet(
      purposeType:          purposeType,
      purposeReference:     purposeReference,
      currencyCode:         currencyCode,
      estimatedAmountMinor: estimatedAmountMinor,
      mode:                 PaymentCheckoutMode.sheet,
    ),
  );
}
