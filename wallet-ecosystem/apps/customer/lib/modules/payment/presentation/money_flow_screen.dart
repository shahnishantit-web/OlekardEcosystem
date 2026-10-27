import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';
import 'package:dio/dio.dart';
import '../application/customer_money_service.dart';
import '../../wallet/presentation/wallet_providers.dart';
import '../../wallet/domain/entities/wallet.dart';
import '../../home/presentation/home_providers.dart';
import '../../transactions/presentation/transaction_providers.dart';
import '../../../core/connectivity/connectivity_service.dart';
import '../../../shared/widgets/pin_pad_widget.dart';
import '../../../core/security/screenshot_guard.dart';

enum _MoneyStep { form, review, pin, status }

class MoneyFlowScreen extends ConsumerStatefulWidget {
  final MoneyFlowKind kind;
  final String? serviceId;
  const MoneyFlowScreen({super.key, required this.kind, this.serviceId});
  @override
  ConsumerState<MoneyFlowScreen> createState() => _MoneyFlowScreenState();
}

class _MoneyFlowScreenState extends ConsumerState<MoneyFlowScreen> {
  final _amount = TextEditingController();
  final _target = TextEditingController();
  final _bankCode = TextEditingController();
  var _method = 'bank_transfer';
  var _step = _MoneyStep.form;
  var _busy = false;
  String? _error;
  String? _signature;
  String _prepareKey = const Uuid().v4();
  String _confirmKey = const Uuid().v4();
  final String _mockBankKey = const Uuid().v4();
  PreparedMoneyPayment? _prepared;
  Map<String, dynamic>? _result;
  final _guard = ScreenshotGuard();

  @override
  void initState() {
    super.initState();
    _guard.enable();
  }

  String get _title => switch (widget.kind) {
        MoneyFlowKind.transfer => 'Send Money',
        MoneyFlowKind.funding => 'Add Money',
        MoneyFlowKind.withdrawal => 'Withdraw Money',
        MoneyFlowKind.service => 'Pay Bill or Service',
      };

  @override
  void dispose() {
    _guard.disable();
    _amount.dispose();
    _target.dispose();
    _bankCode.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final wallet = ref.watch(walletProvider);
    final offline = ref.watch(isOfflineProvider);
    return Scaffold(
        appBar: AppBar(title: Text(_title)),
        body: wallet.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (error, _) => Center(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(paymentErrorMessage(error), textAlign: TextAlign.center),
            TextButton(
                onPressed: () => ref.invalidate(walletProvider),
                child: const Text('Retry')),
          ])),
          data: (wallet) => SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (offline)
                    const Text(
                        'You are offline. Reconnect before submitting a payment.'),
                  if (_error != null)
                    Padding(
                        padding: const EdgeInsets.only(bottom: 16),
                        child: Text(_error!,
                            style: TextStyle(
                                color: Theme.of(context).colorScheme.error))),
                  if (_busy) const LinearProgressIndicator(),
                  const SizedBox(height: 16),
                  ...switch (_step) {
                    _MoneyStep.form => _form(wallet, offline),
                    _MoneyStep.review => _review(wallet, offline),
                    _MoneyStep.pin => [
                        const Text('Enter your PIN',
                            style: TextStyle(fontSize: 22)),
                        const SizedBox(height: 20),
                        if (!_busy && !offline)
                          PinPadWidget(onCompleted: _confirm),
                        TextButton(
                            onPressed: _busy
                                ? null
                                : () =>
                                    setState(() => _step = _MoneyStep.review),
                            child: const Text('Back to review'))
                      ],
                    _MoneyStep.status => _status(wallet, offline),
                  },
                ],
              )),
        ));
  }

  List<Widget> _form(Wallet wallet, bool offline) => [
        Text('Wallet ${wallet.walletNumber} · ${wallet.currencyCode}'),
        const SizedBox(height: 16),
        if (widget.kind == MoneyFlowKind.funding ||
            widget.kind == MoneyFlowKind.withdrawal)
          DropdownButtonFormField<String>(
              initialValue: _method,
              decoration: const InputDecoration(labelText: 'Method'),
              items: const [
                DropdownMenuItem(
                    value: 'bank_transfer', child: Text('Bank transfer')),
                DropdownMenuItem(
                    value: 'mobile_money', child: Text('Mobile money'))
              ],
              onChanged:
                  _busy ? null : (value) => setState(() => _method = value!)),
        if (widget.kind != MoneyFlowKind.funding) ...[
          const SizedBox(height: 16),
          TextFormField(
              key: const Key('money-target'),
              controller: _target,
              decoration: InputDecoration(
                  labelText: switch (widget.kind) {
                MoneyFlowKind.transfer => 'Recipient phone or wallet ID',
                MoneyFlowKind.service => 'Customer / bill reference',
                _ => _method == 'bank_transfer'
                    ? 'Bank account number'
                    : 'Mobile money number',
              }),
              enabled: !_busy),
        ],
        const SizedBox(height: 16),
        if (widget.kind == MoneyFlowKind.withdrawal &&
            _method == 'bank_transfer')
          TextFormField(
              controller: _bankCode,
              decoration: const InputDecoration(labelText: 'Bank code'),
              enabled: !_busy),
        TextFormField(
            key: const Key('money-amount'),
            controller: _amount,
            decoration:
                InputDecoration(labelText: 'Amount (${wallet.currencyCode})'),
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            enabled: !_busy),
        const SizedBox(height: 24),
        FilledButton(
            onPressed: _busy || offline ? null : () => _prepare(wallet),
            child: const Text('Continue')),
      ];

  List<Widget> _review(Wallet wallet, bool offline) {
    final payment = _prepared!;
    final net =
        payment.request['net_amount'] ?? payment.request['net_payout_amount'];
    return [
      const Text('Review payment', style: TextStyle(fontSize: 24)),
      const SizedBox(height: 16),
      Text(payment.recipient),
      ListTile(
          title: const Text('Amount'),
          trailing: Text(_money(payment.amount, wallet))),
      ListTile(
          title: const Text('Fee'),
          trailing: Text(_money(payment.fee, wallet))),
      if (net is int)
        ListTile(
            title: const Text('Net amount'),
            trailing: Text(_money(net, wallet))),
      if (widget.kind == MoneyFlowKind.service)
        ListTile(
            title: const Text('Total debit'),
            trailing: Text(_money(payment.amount + payment.fee, wallet))),
      FilledButton(
          onPressed: _busy || offline
              ? null
              : () => setState(() => _step = _MoneyStep.pin),
          child: const Text('Confirm with PIN')),
      TextButton(
          onPressed:
              _busy ? null : () => setState(() => _step = _MoneyStep.form),
          child: const Text('Edit details')),
    ];
  }

  List<Widget> _status(Wallet wallet, bool offline) {
    final status = _result!['status'] as String;
    final completed = {'completed', 'succeeded', 'success'}.contains(status);
    final failed =
        {'failed', 'cancelled', 'expired', 'rejected'}.contains(status);
    final reference = (_result!['transaction_reference'] ??
        _result!['transfer_reference'] ??
        _result!['id']) as String;
    return [
      Icon(
          completed
              ? Icons.check_circle_outline
              : failed
                  ? Icons.error_outline
                  : Icons.schedule,
          size: 64,
          color: completed ? Colors.green : null),
      const SizedBox(height: 16),
      Text(
          completed
              ? 'Payment completed'
              : failed
                  ? 'Payment ${status.replaceAll('_', ' ')}'
                  : 'Payment pending',
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 24)),
      const SizedBox(height: 16),
      SelectableText('Reference: $reference'),
      TextButton(
          onPressed: () => Clipboard.setData(ClipboardData(text: reference)),
          child: const Text('Copy reference')),
      if (_result!['bank_reference_code'] != null)
        SelectableText('Bank reference: ${_result!['bank_reference_code']}'),
      if (!completed && !failed) ...[
        const Text(
            'Awaiting confirmation. Do not submit the same payment again.'),
        FilledButton(
            onPressed: _busy || offline ? null : _refreshStatus,
            child: const Text('Check status')),
        if (const bool.fromEnvironment('ENABLE_DEV_MOCK_BANK'))
          if ((widget.kind == MoneyFlowKind.funding &&
                  _result!['bank_reference_code'] != null) ||
              widget.kind == MoneyFlowKind.withdrawal)
            OutlinedButton(
                onPressed: _busy || offline ? null : _completeMockBank,
                child: const Text('Development mock bank: complete payment')),
      ],
      TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Done')),
    ];
  }

  String _money(int amount, Wallet wallet) {
    final digits = amount.toString().padLeft(wallet.precision + 1, '0');
    final formatted = wallet.precision == 0
        ? digits
        : '${digits.substring(0, digits.length - wallet.precision)}.${digits.substring(digits.length - wallet.precision)}';
    return '${wallet.currencyCode} $formatted';
  }

  Future<void> _prepare(Wallet wallet) async {
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final amount =
          parseMinorAmount(_amount.text, precision: wallet.precision);
      final target = _target.text.trim();
      if (widget.kind != MoneyFlowKind.funding && target.isEmpty) {
        throw const FormatException(
            'Enter the recipient or account reference.');
      }
      final signature =
          '${wallet.id}|$amount|$target|$_method|${_bankCode.text.trim()}|${widget.serviceId}';
      if (widget.kind == MoneyFlowKind.withdrawal &&
          _method == 'bank_transfer' &&
          _bankCode.text.trim().isEmpty) {
        throw const FormatException('Enter the bank code.');
      }
      if (_signature != signature) {
        _signature = signature;
        _prepareKey = const Uuid().v4();
        _confirmKey = const Uuid().v4();
        _prepared = null;
      }
      final prepared = _prepared ??
          await ref.read(customerMoneyServiceProvider).prepare(
              kind: widget.kind,
              wallet: wallet,
              amount: amount,
              target: target,
              method: _method,
              bankCode: _bankCode.text.trim(),
              idempotencyKey: _prepareKey,
              serviceId: widget.serviceId);
      if (mounted) {
        setState(() {
          _prepared = prepared;
          _step = _MoneyStep.review;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = paymentErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _confirm(String pin) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref
          .read(customerMoneyServiceProvider)
          .confirm(_prepared!, pin, _confirmKey);
      if (!mounted) return;
      _invalidateBalances();
      setState(() {
        _result = result;
        _step = _MoneyStep.status;
      });
    } catch (e) {
      if (e is DioException && {401, 422}.contains(e.response?.statusCode)) {
        _confirmKey = const Uuid().v4();
      }
      if (mounted) setState(() => _error = paymentErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _refreshStatus() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref
          .read(customerMoneyServiceProvider)
          .status(widget.kind, _result!['id'] as String);
      if (mounted) {
        _invalidateBalances();
        setState(() => _result = result);
      }
    } catch (e) {
      if (mounted) setState(() => _error = paymentErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _completeMockBank() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref
          .read(customerMoneyServiceProvider)
          .completeMockBank(_result!['id'] as String, _mockBankKey,
              withdrawal: widget.kind == MoneyFlowKind.withdrawal);
      if (mounted) {
        _invalidateBalances();
        setState(() => _result = result);
      }
    } catch (e) {
      if (mounted) setState(() => _error = paymentErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _invalidateBalances() {
    ref.invalidate(walletProvider);
    ref.invalidate(homeSummaryProvider);
    ref.invalidate(paymentHistoryProvider('me'));
  }
}
