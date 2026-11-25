import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';
import '../withdrawal_providers.dart';
import '../../domain/entities/withdrawal_result.dart';
import '../../../../core/connectivity/connectivity_service.dart';
import '../../../../core/security/screenshot_guard.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../modules/wallet/presentation/wallet_providers.dart';
import '../../../../shared/widgets/amount_display.dart';
import '../../../../shared/widgets/pin_pad_widget.dart';
import '../../../../shared/widgets/primary_button.dart';
import '../../../../shared/widgets/success_view.dart';
import '../../../../modules/wallet/presentation/widgets/wallet_components.dart';

enum _Step { form, preview, pin, result }

class WithdrawScreen extends ConsumerStatefulWidget {
  const WithdrawScreen({super.key});

  @override
  ConsumerState<WithdrawScreen> createState() => _WithdrawScreenState();
}

class _WithdrawScreenState extends ConsumerState<WithdrawScreen> {
  _Step _step = _Step.form;
  final _agentController = TextEditingController();
  final _amountController = TextEditingController();
  WithdrawalPreview? _preview;
  WithdrawalResult? _result;
  bool _isLoading = false;
  String? _error;
  final _guard = ScreenshotGuard();

  @override
  void initState() {
    super.initState();
    _guard.enable();
  }

  @override
  void dispose() {
    _agentController.dispose();
    _amountController.dispose();
    _guard.disable();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isOffline = ref.watch(isOfflineProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Withdraw')),
      body: Column(children: [
        const OfflineBanner(),
        Expanded(child: _buildStep(isOffline)),
      ]),
    );
  }

  Widget _buildStep(bool isOffline) {
    return switch (_step) {
      _Step.form => _buildForm(isOffline),
      _Step.preview => _buildPreview(),
      _Step.pin => _buildPin(),
      _Step.result => _buildResult(),
    };
  }

  Widget _buildForm(bool isOffline) => ListView(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
        children: [
          const WalletFlowHeading(
            step: 1,
            title: 'Withdraw cash',
            subtitle: 'Send the withdrawal to an OleKard agent.',
          ),
          const SizedBox(height: 24),
          Text('Agent wallet number',
              style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 9),
          TextFormField(
            controller: _agentController,
            keyboardType: TextInputType.text,
            textInputAction: TextInputAction.next,
            decoration: const InputDecoration(
              labelText: 'Agent wallet number',
              prefixIcon: Icon(Icons.storefront_outlined),
            ),
          ),
          const SizedBox(height: 22),
          Text('Withdrawal amount',
              style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 9),
          TextFormField(
            controller: _amountController,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textInputAction: TextInputAction.done,
            decoration: const InputDecoration(
              hintText: '0.00',
              prefixIcon: Icon(Icons.payments_outlined),
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            _FlowError(message: _error!),
          ],
          const SizedBox(height: 24),
          PrimaryButton(
            label: 'Review withdrawal',
            isLoading: _isLoading,
            onPressed: isOffline ? () => _showOfflineDialog() : _getPreview,
          ),
        ],
      );

  Widget _buildPreview() {
    final p = _preview!;
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      children: [
        const WalletFlowHeading(
          step: 2,
          title: 'Review withdrawal',
          subtitle: 'Check the agent and total debit before continuing.',
        ),
        const SizedBox(height: 24),
        WalletSummaryPanel(children: [
          _Row('Agent', p.agentName),
          _RowAmount('Amount', p.amountMinorUnits, p.currencyCode),
          _RowAmount('Fee', p.feeMinorUnits, p.currencyCode),
          _RowAmount('Total debit', p.amountMinorUnits + p.feeMinorUnits,
              p.currencyCode),
        ]),
        const SizedBox(height: 24),
        PrimaryButton(
          label: 'Continue to PIN',
          onPressed: () => setState(() => _step = _Step.pin),
        ),
        TextButton(
          onPressed: () => setState(() => _step = _Step.form),
          child: const Text('Back to details'),
        ),
      ],
    );
  }

  Widget _buildPin() => ListView(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
        children: [
          const WalletFlowHeading(
            step: 3,
            title: 'Confirm securely',
            subtitle: 'Enter your PIN to authorize this withdrawal.',
          ),
          const SizedBox(height: 22),
          if (_isLoading)
            const Padding(
              padding: EdgeInsets.all(40),
              child: Center(child: CircularProgressIndicator()),
            )
          else
            PinPadWidget(onCompleted: _submit),
          TextButton(
            onPressed: () => setState(() => _step = _Step.preview),
            child: const Text('Back to review'),
          ),
        ],
      );

  Widget _buildResult() {
    final r = _result!;
    return SuccessView(
      title: 'Withdrawal Initiated',
      referenceNumber: r.referenceNumber,
      actionLabel: 'Back to Home',
      onAction: () => Navigator.of(context).pop(),
    );
  }

  Future<void> _getPreview() async {
    final amount = double.tryParse(_amountController.text.trim());
    if (amount == null || amount <= 0 || _agentController.text.isEmpty) {
      setState(() => _error = 'Enter a valid amount and agent wallet number');
      return;
    }
    setState(() {
      _isLoading = true;
      _error = null;
    });
    try {
      final preview =
          await ref.read(withdrawalRepositoryProvider).previewWithdrawal(
                agentWalletNumber: _agentController.text.trim(),
                amountMinorUnits: (amount * 100).round(),
                currencyCode: 'KES',
              );
      setState(() {
        _preview = preview;
        _step = _Step.preview;
      });
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      setState(() => _isLoading = false);
    }
  }

  Future<void> _submit(String pin) async {
    final amount = double.parse(_amountController.text.trim());
    setState(() => _isLoading = true);
    try {
      final result =
          await ref.read(withdrawalRepositoryProvider).executeWithdrawal(
                WithdrawRequestDto(
                  idempotencyKey: const Uuid().v4(),
                  agentWalletNumber: _agentController.text.trim(),
                  amountMinorUnits: (amount * 100).round(),
                  currencyCode: 'KES',
                ),
              );
      ref.invalidate(walletProvider);
      setState(() {
        _result = result;
        _step = _Step.result;
      });
    } catch (e) {
      setState(() {
        _error = e.toString();
        _step = _Step.form;
      });
    } finally {
      setState(() => _isLoading = false);
    }
  }

  void _showOfflineDialog() {
    showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('You are offline'),
        content: const Text('Please reconnect to perform transactions.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context), child: const Text('OK'))
        ],
      ),
    );
  }
}

class _Row extends StatelessWidget {
  final String label;
  final String value;
  const _Row(this.label, this.value);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Row(
          children: [
            Expanded(
              child: Text(label,
                  style: AppTypography.label.copyWith(
                    color: AppColors.textSecondary,
                  )),
            ),
            Flexible(
              child: Text(value,
                  textAlign: TextAlign.end,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.body.copyWith(
                    fontWeight: FontWeight.w600,
                  )),
            ),
          ],
        ),
      );
}

class _RowAmount extends StatelessWidget {
  final String label;
  final int minorUnits;
  final String currencyCode;
  const _RowAmount(this.label, this.minorUnits, this.currencyCode);

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Expanded(
            child: Text(label,
                style: AppTypography.label.copyWith(
                  color: AppColors.textSecondary,
                )),
          ),
          Flexible(
            child: Align(
              alignment: Alignment.centerRight,
              child: AmountDisplay(
                  amountMinorUnits: minorUnits, currencyCode: currencyCode),
            ),
          ),
        ],
      );
}

class _FlowError extends StatelessWidget {
  const _FlowError({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(13),
        decoration: BoxDecoration(
          color: AppColors.errorBg,
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Icon(Icons.error_outline_rounded,
                size: 19, color: AppColors.error),
            const SizedBox(width: 9),
            Expanded(
              child: Text(message,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AppColors.error,
                      )),
            ),
          ],
        ),
      );
}
