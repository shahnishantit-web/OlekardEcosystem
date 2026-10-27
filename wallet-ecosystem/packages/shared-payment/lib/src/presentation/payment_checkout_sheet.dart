import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import '../application/payment_notifier.dart';
import '../application/step_up_service.dart';
import '../domain/checkout_state.dart';
import '../domain/models/checkout_result.dart';
import '../domain/models/payment_option.dart';
import 'payment_status_screen.dart';
import 'widgets/payment_option_tile.dart';
import 'widgets/wallet_balance_tile.dart';
import 'widgets/payment_amount_summary.dart';

enum PaymentCheckoutMode { sheet, page }

/// Shared payment checkout entry point.
///
/// NEVER parses redirect URL parameters as payment proof.
/// Persists intent ID to SharedPreferences before opening redirect URL.
class PaymentCheckoutSheet extends ConsumerStatefulWidget {
  final String purposeType;
  final String purposeReference;
  final String currencyCode;
  final int estimatedAmountMinor;
  final PaymentCheckoutMode mode;
  final String? returnRoute;

  const PaymentCheckoutSheet({
    super.key,
    required this.purposeType,
    required this.purposeReference,
    required this.currencyCode,
    required this.estimatedAmountMinor,
    this.mode = PaymentCheckoutMode.sheet,
    this.returnRoute,
  });

  @override
  ConsumerState<PaymentCheckoutSheet> createState() =>
      _PaymentCheckoutSheetState();
}

class _PaymentCheckoutSheetState extends ConsumerState<PaymentCheckoutSheet>
    with WidgetsBindingObserver {
  late final PaymentCheckoutRequest _request = (
    purposeType:          widget.purposeType,
    purposeReference:     widget.purposeReference,
    currencyCode:         widget.currencyCode,
    estimatedAmountMinor: widget.estimatedAmountMinor,
    returnRoute:          widget.returnRoute,
  );

  late final _provider = paymentNotifierProvider(_request);

  PaymentNotifier get _notifier => ref.read(_provider.notifier);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// On app foreground resume: if AwaitingRedirect, trigger backend poll.
  /// Scheme match is only a trigger for polling — NOT payment proof (FR-007).
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      final current = ref.read(_provider).value;
      if (current is AwaitingRedirect) {
        // Dual protection: scheme-check gates polling start; poll result is the only proof.
        // allowedReturnSchemes is empty for sdk_launch/app_switch — always poll on resume.
        if (current.allowedReturnSchemes.isEmpty) {
          _notifier.pollStatus(current.paymentIntentId);
        } else {
          // For redirect flows, any foreground resume triggers polling.
          // The scheme allowlist is a belt-and-suspenders signal, not a confirmation gate.
          _notifier.pollStatus(current.paymentIntentId);
        }
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final asyncState = ref.watch(_provider);
    final body = asyncState.when(
      loading: () => const _LoadingView(),
      error: (e, _) =>
          _ErrorView(error: e, onRetry: () => ref.invalidate(_provider)),
      data: _buildState,
    );
    if (widget.mode == PaymentCheckoutMode.page) {
      return Scaffold(body: body);
    }
    return body;
  }

  Widget _buildState(PaymentCheckoutState state) {
    return switch (state) {
      LoadingOptions()  => const _LoadingView(),
      EmptyOptions()    => _EmptyOptionsView(onClose: _close),
      OptionsLoaded(
        options: final options,
        selectedOption: final selected,
      ) =>
        _OptionsView(
          options:    options,
          selected:   selected,
          onSelect:   (opt) => _notifier.selectOption(opt),
          onConfirm:  selected != null ? () => _notifier.confirmPayment() : null,
          onCancel:   () => _notifier.cancelPayment(),
        ),
      ConfirmingPayment() =>
        const _LoadingView(message: 'Processing payment…'),
      AwaitingRedirect(redirectUrl: final url) => _RedirectView(
          redirectUrl:   url,
          onOpenBrowser: () => _openRedirect(url),
        ),
      PollingStatus() =>
        const _LoadingView(message: 'Waiting for confirmation…'),
      InsufficientFunds(
        requiredMinor:  final req,
        availableMinor: final avail,
      ) =>
        _InsufficientFundsView(
          requiredMinor:  req,
          availableMinor: avail,
          currencyCode:   widget.currencyCode,
          onClose:        _close,
        ),
      StepUpRequired() => _StepUpView(
          onComplete: (token) => _notifier.confirmPayment(stepUpToken: token),
          onCancel:   () => _notifier.cancelPayment(),
        ),
      RecoveringUnknown(
        paymentIntentId:  final id,
        supportReference: final ref,
      ) =>
        _RecoveringUnknownView(
          paymentIntentId:  id,
          supportReference: ref,
          onClose:          _close,
        ),
      RecoveringPending(paymentIntentId: final id) =>
        _RecoveringPendingView(paymentIntentId: id),
      RecoveringFulfilling(paymentIntentId: final id) =>
        _RecoveringFulfillingView(paymentIntentId: id),
      PaymentSucceeded() ||
      PaymentFailed()    ||
      PaymentCancelled() ||
      PaymentExpired()   =>
        _buildTerminal(state),
    };
  }

  Widget _buildTerminal(PaymentCheckoutState state) {
    return PaymentStatusScreen(
      state:   state,
      onClose: () {
        final result = _resultFromState(state);
        if (state is PaymentSucceeded && widget.returnRoute != null) {
          GoRouter.of(context).go(widget.returnRoute!);
        } else {
          Navigator.of(context).pop(result);
        }
      },
      onRetry: () => ref.invalidate(_provider),
    );
  }

  void _close() => Navigator.of(context).pop(const CheckoutResult(succeeded: false));

  Future<void> _openRedirect(String url) async {
    final uri = Uri.tryParse(url);
    if (uri != null && await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  CheckoutResult _resultFromState(PaymentCheckoutState state) {
    return switch (state) {
      PaymentSucceeded(paymentIntentId: final id, amountMinor: _) =>
        CheckoutResult(succeeded: true, paymentIntentId: id),
      PaymentFailed(errorCode: final code) =>
        CheckoutResult(succeeded: false, errorCode: code),
      _ => const CheckoutResult(succeeded: false),
    };
  }
}

// ─── Convenience entry point ──────────────────────────────────────────────────

Future<CheckoutResult?> showPaymentCheckout({
  required BuildContext context,
  required String purposeType,
  required String purposeReference,
  required String currencyCode,
  required int estimatedAmountMinor,
  String? returnRoute,
}) {
  return showModalBottomSheet<CheckoutResult>(
    context:            context,
    isScrollControlled: true,
    useSafeArea:        true,
    builder: (_) => PaymentCheckoutSheet(
      purposeType:          purposeType,
      purposeReference:     purposeReference,
      currencyCode:         currencyCode,
      estimatedAmountMinor: estimatedAmountMinor,
      mode:                 PaymentCheckoutMode.sheet,
      returnRoute:          returnRoute,
    ),
  );
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

class _EmptyOptionsView extends StatelessWidget {
  final VoidCallback onClose;
  const _EmptyOptionsView({required this.onClose});

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.payment_outlined, size: 64, color: Colors.grey),
              const SizedBox(height: 16),
              const Text('No Payment Methods Available',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                  textAlign: TextAlign.center),
              const SizedBox(height: 8),
              const Text(
                'No payment options are available for this transaction right now.',
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 24),
              ElevatedButton(onPressed: onClose, child: const Text('Close')),
            ],
          ),
        ),
      );
}

class _OptionsView extends StatelessWidget {
  final List<PaymentOption> options;
  final PaymentOption? selected;
  final void Function(PaymentOption) onSelect;
  final VoidCallback? onConfirm;
  final VoidCallback onCancel;

  const _OptionsView({
    required this.options,
    required this.selected,
    required this.onSelect,
    required this.onConfirm,
    required this.onCancel,
  });

  @override
  Widget build(BuildContext context) {
    final walletOption = options.where((o) => o.isWallet).firstOrNull;

    return Column(
      mainAxisSize:       MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child:   Text('Choose Payment Method',
              style: Theme.of(context).textTheme.titleLarge),
        ),
        if (walletOption != null)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child:   WalletBalanceTile(option: walletOption),
          ),
        const SizedBox(height: 8),
        ...options.map((opt) => PaymentOptionTile(
              option:     opt,
              isSelected: selected?.providerCode == opt.providerCode,
              onTap:      opt.isRestricted ? null : () => onSelect(opt),
            )),
        if (selected != null)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: PaymentAmountSummary(option: selected!),
          ),
        const SizedBox(height: 16),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child:   ElevatedButton(
            onPressed: onConfirm,
            child:     const Text('Pay Now'),
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
            const Icon(Icons.account_balance_wallet,
                size: 64, color: Colors.orange),
            const SizedBox(height: 16),
            const Text('Insufficient Balance',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Text(
                'You need ${_fmt(requiredMinor)} but only have ${_fmt(availableMinor)} available.'),
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

class _FallbackView extends StatelessWidget {
  final String? fallbackMessage;
  final VoidCallback onClose;

  const _FallbackView({this.fallbackMessage, required this.onClose});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.phone_android, size: 64, color: Colors.grey),
            const SizedBox(height: 16),
            const Text('Cannot Open Bank App',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Text(
              fallbackMessage ??
                  'Please open your bank app manually to complete the payment.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            ElevatedButton(onPressed: onClose, child: const Text('Close')),
          ],
        ),
      ),
    );
  }
}

class _StepUpView extends ConsumerStatefulWidget {
  final void Function(String token) onComplete;
  final VoidCallback onCancel;

  const _StepUpView({required this.onComplete, required this.onCancel});

  @override
  ConsumerState<_StepUpView> createState() => _StepUpViewState();
}

class _StepUpViewState extends ConsumerState<_StepUpView> {
  bool _busy = false;
  String? _error;

  Future<void> _acquire() async {
    if (_busy) return;
    setState(() { _busy = true; _error = null; });
    try {
      // Token is never persisted; discarded after forwarding to notifier.
      final token = await ref
          .read(stepUpServiceProvider)
          .acquireToken(scope: 'payment.initiate');
      if (mounted) widget.onComplete(token);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

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
            const Text('Security Verification Required',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            const Text('Please verify your identity to proceed.'),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(_error!, style: const TextStyle(color: Colors.red)),
            ],
            const SizedBox(height: 24),
            if (_busy)
              const CircularProgressIndicator()
            else
              ElevatedButton(
                  onPressed: _acquire,
                  child: const Text('Verify')),
            TextButton(
                onPressed: _busy ? null : widget.onCancel,
                child: const Text('Cancel')),
          ],
        ),
      ),
    );
  }
}

class _RecoveringUnknownView extends StatelessWidget {
  final String paymentIntentId;
  final String supportReference;
  final VoidCallback onClose;

  const _RecoveringUnknownView({
    required this.paymentIntentId,
    required this.supportReference,
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
            const Icon(Icons.help_outline, size: 64, color: Colors.orange),
            const SizedBox(height: 16),
            const Text('Outcome Unknown',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            const Text(
              'Do not retry — we are checking with your bank.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text('Reference: $supportReference',
                style: const TextStyle(fontSize: 12, color: Colors.grey)),
            const SizedBox(height: 24),
            // NO retry button per spec: RecoveringUnknown must not offer retry.
            TextButton(onPressed: onClose, child: const Text('Close')),
          ],
        ),
      ),
    );
  }
}

class _RecoveringPendingView extends StatelessWidget {
  final String paymentIntentId;

  const _RecoveringPendingView({required this.paymentIntentId});

  @override
  Widget build(BuildContext context) {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(),
            SizedBox(height: 16),
            Text('Payment Processing',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            SizedBox(height: 8),
            Text(
              'Your payment is being processed. This may take a moment.',
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}

class _RecoveringFulfillingView extends StatelessWidget {
  final String paymentIntentId;

  const _RecoveringFulfillingView({required this.paymentIntentId});

  @override
  Widget build(BuildContext context) {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.check_circle_outline, size: 64, color: Colors.green),
            SizedBox(height: 16),
            Text('Payment Confirmed',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            SizedBox(height: 8),
            Text(
              'Crediting your wallet — this usually takes under a minute.',
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}
