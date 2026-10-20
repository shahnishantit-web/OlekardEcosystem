import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';
import '../funding_providers.dart';
import '../../domain/entities/funding_result.dart';
import '../../../../core/connectivity/connectivity_service.dart';
import '../../../../core/security/screenshot_guard.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../modules/wallet/presentation/wallet_providers.dart';
import '../../../../shared/widgets/amount_display.dart';
import '../../../../shared/widgets/offline_banner.dart';
import '../../../../shared/widgets/pin_pad_widget.dart';
import '../../../../shared/widgets/primary_button.dart';
import '../../../../shared/widgets/success_view.dart';

enum _Step { form, preview, pin, result }

class AddMoneyScreen extends ConsumerStatefulWidget {
  const AddMoneyScreen({super.key});

  @override
  ConsumerState<AddMoneyScreen> createState() => _AddMoneyScreenState();
}

class _AddMoneyScreenState extends ConsumerState<AddMoneyScreen> {
  _Step _step = _Step.form;
  final _amountController = TextEditingController();
  FundingMethod _method = FundingMethod.bankTransfer;
  Map<String, dynamic>? _preview;
  String? _referenceNumber;
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
    _amountController.dispose();
    _guard.disable();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isOffline = ref.watch(isOfflineProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Add Money')),
      body: Column(
        children: [
          const OfflineBanner(),
          Expanded(child: _buildStep(context, isOffline)),
        ],
      ),
    );
  }

  Widget _buildStep(BuildContext context, bool isOffline) {
    return switch (_step) {
      _Step.form => _buildForm(isOffline),
      _Step.preview => _buildPreview(),
      _Step.pin => _buildPin(),
      _Step.result => _buildResult(),
    };
  }

  Widget _buildForm(bool isOffline) {
    final limitsAsync = ref.watch(walletLimitsProvider);
    return Padding(
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Select funding method', style: AppTypography.label),
          RadioGroup<FundingMethod>(
            groupValue: _method,
            onChanged: (v) => setState(() => _method = v!),
            child: const Column(
              children: [
                RadioListTile<FundingMethod>(
                  title: Text('Bank Transfer'),
                  value: FundingMethod.bankTransfer,
                ),
                RadioListTile<FundingMethod>(
                  title: Text('Agent Cash-In'),
                  value: FundingMethod.agentCashIn,
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          limitsAsync.when(
            loading: () => const SizedBox.shrink(),
            error: (_, __) => const SizedBox.shrink(),
            data: (limits) => Text(
              'Min: ${limits.minFundMinorUnits / 100} / '
              'Max: ${limits.maxFundMinorUnits / 100}',
              style: AppTypography.caption.copyWith(color: Colors.grey),
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextFormField(
            controller: _amountController,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'Amount'),
          ),
          if (_error != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(_error!, style: const TextStyle(color: AppColors.error)),
          ],
          const Spacer(),
          PrimaryButton(
            label: 'Continue',
            isLoading: _isLoading,
            onPressed: isOffline
                ? () => _showOfflineDialog(context)
                : _getPreview,
          ),
        ],
      ),
    );
  }

  Widget _buildPreview() {
    final p = _preview!;
    final amount = p['amount_minor_units'] as int;
    final fee = p['fee_minor_units'] as int? ?? 0;
    final currency = p['currency_code'] as String? ?? 'KES';

    return Padding(
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Confirm', style: AppTypography.headline),
          const SizedBox(height: AppSpacing.lg),
          _SummaryRow('Amount', AmountDisplay(amountMinorUnits: amount, currencyCode: currency)),
          _SummaryRow('Fee', AmountDisplay(amountMinorUnits: fee, currencyCode: currency)),
          _SummaryRow('You pay', AmountDisplay(amountMinorUnits: amount + fee, currencyCode: currency)),
          const Spacer(),
          PrimaryButton(label: 'Authorize with PIN', onPressed: () => setState(() => _step = _Step.pin)),
        ],
      ),
    );
  }

  Widget _buildPin() {
    return Padding(
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Enter PIN to authorize', style: AppTypography.title, textAlign: TextAlign.center),
          const SizedBox(height: AppSpacing.xl),
          if (_isLoading)
            const Center(child: CircularProgressIndicator())
          else
            PinPadWidget(onCompleted: _submitFunding),
        ],
      ),
    );
  }

  Widget _buildResult() => SuccessView(
        title: 'Funding Initiated',
        referenceNumber: _referenceNumber ?? '',
        actionLabel: 'Back to Home',
        onAction: () => Navigator.of(context).pop(),
      );

  Future<void> _getPreview() async {
    final amountText = _amountController.text.trim();
    final amount = double.tryParse(amountText);
    if (amount == null || amount <= 0) {
      setState(() => _error = 'Enter a valid amount');
      return;
    }
    setState(() { _isLoading = true; _error = null; });
    try {
      final preview = await ref.read(fundingRepositoryProvider).previewFunding(
            amountMinorUnits: (amount * 100).round(),
            currencyCode: 'KES',
            method: _method,
          );
      setState(() { _preview = preview; _step = _Step.preview; });
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      setState(() => _isLoading = false);
    }
  }

  Future<void> _submitFunding(String pin) async {
    final amount = double.parse(_amountController.text.trim());
    setState(() => _isLoading = true);
    try {
      final idempotencyKey = const Uuid().v4();
      final result = await ref.read(fundingRepositoryProvider).initiateFunding(
            FundRequestDto(
              idempotencyKey: idempotencyKey,
              method: _method,
              amountMinorUnits: (amount * 100).round(),
              currencyCode: 'KES',
            ),
          );
      ref.invalidate(walletProvider);
      setState(() { _referenceNumber = result.referenceNumber; _step = _Step.result; });
    } catch (e) {
      setState(() { _error = e.toString(); _step = _Step.form; });
    } finally {
      setState(() => _isLoading = false);
    }
  }

  void _showOfflineDialog(BuildContext context) {
    showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('You are offline'),
        content: const Text('Please reconnect to perform transactions.'),
        actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('OK'))],
      ),
    );
  }
}

class _SummaryRow extends StatelessWidget {
  final String label;
  final Widget value;
  const _SummaryRow(this.label, this.value);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(label, style: AppTypography.label),
            value,
          ],
        ),
      );
}
