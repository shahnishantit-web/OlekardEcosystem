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
import '../../../../modules/wallet/presentation/widgets/wallet_components.dart';

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
      body: Column(children: [
        const OfflineBanner(),
        Expanded(child: _buildStep(context, isOffline)),
      ]),
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
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      children: [
        const WalletFlowHeading(
          step: 1,
          title: 'Add money to your wallet',
          subtitle: 'Choose a funding method and enter an amount.',
        ),
        const SizedBox(height: 24),
        Text('Funding method', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 10),
        RadioGroup<FundingMethod>(
          groupValue: _method,
          onChanged: (value) => setState(() => _method = value!),
          child: Column(
            children: [
              _FundingMethodTile(
                icon: Icons.account_balance_outlined,
                title: 'Bank transfer',
                subtitle: 'Move funds from your bank account.',
                value: FundingMethod.bankTransfer,
                selected: _method == FundingMethod.bankTransfer,
              ),
              const SizedBox(height: 10),
              _FundingMethodTile(
                icon: Icons.storefront_outlined,
                title: 'Agent cash-in',
                subtitle: 'Visit an OleKard agent to add cash.',
                value: FundingMethod.agentCashIn,
                selected: _method == FundingMethod.agentCashIn,
              ),
            ],
          ),
        ),
        const SizedBox(height: 22),
        Text('Amount', style: Theme.of(context).textTheme.titleMedium),
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
        const SizedBox(height: 10),
        limitsAsync.when(
          loading: () => const SizedBox.shrink(),
          error: (_, __) => const SizedBox.shrink(),
          data: (limits) => Container(
            padding: const EdgeInsets.all(13),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: AppColors.outline),
            ),
            child: Row(
              children: [
                const Icon(Icons.info_outline_rounded,
                    size: 18, color: AppColors.textSecondary),
                const SizedBox(width: 9),
                Expanded(
                  child: Text(
                    'Limits: ${limits.minFundMinorUnits / 100} – ${limits.maxFundMinorUnits / 100}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ),
              ],
            ),
          ),
        ),
        if (_error != null) ...[
          const SizedBox(height: 12),
          _WalletFlowError(message: _error!),
        ],
        const SizedBox(height: 24),
        PrimaryButton(
          label: 'Review top-up',
          isLoading: _isLoading,
          onPressed:
              isOffline ? () => _showOfflineDialog(context) : _getPreview,
        ),
      ],
    );
  }

  Widget _buildPreview() {
    final p = _preview!;
    final amount = p['amount_minor_units'] as int;
    final fee = p['fee_minor_units'] as int? ?? 0;
    final currency = p['currency_code'] as String? ?? 'KES';

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      children: [
        const WalletFlowHeading(
          step: 2,
          title: 'Review your top-up',
          subtitle: 'Check the amount and fee before authorizing.',
        ),
        const SizedBox(height: 24),
        WalletSummaryPanel(children: [
          _SummaryRow('Top-up amount',
              AmountDisplay(amountMinorUnits: amount, currencyCode: currency)),
          _SummaryRow('Fee',
              AmountDisplay(amountMinorUnits: fee, currencyCode: currency)),
          _SummaryRow(
              'Total to pay',
              AmountDisplay(
                  amountMinorUnits: amount + fee, currencyCode: currency)),
        ]),
        const SizedBox(height: 24),
        PrimaryButton(
          label: 'Continue to PIN',
          onPressed: () => setState(() => _step = _Step.pin),
        ),
        const SizedBox(height: 8),
        TextButton(
          onPressed: () => setState(() => _step = _Step.form),
          child: const Text('Back to details'),
        ),
      ],
    );
  }

  Widget _buildPin() {
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      children: [
        const WalletFlowHeading(
          step: 3,
          title: 'Confirm securely',
          subtitle: 'Enter your PIN to authorize this top-up.',
        ),
        const SizedBox(height: 22),
        if (_isLoading)
          const Padding(
            padding: EdgeInsets.all(40),
            child: Center(child: CircularProgressIndicator()),
          )
        else
          PinPadWidget(onCompleted: _submitFunding),
        const SizedBox(height: 8),
        TextButton(
          onPressed: () => setState(() => _step = _Step.preview),
          child: const Text('Back to review'),
        ),
      ],
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
    setState(() {
      _isLoading = true;
      _error = null;
    });
    try {
      final preview = await ref.read(fundingRepositoryProvider).previewFunding(
            amountMinorUnits: (amount * 100).round(),
            currencyCode: 'KES',
            method: _method,
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
      setState(() {
        _referenceNumber = result.referenceNumber;
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

  void _showOfflineDialog(BuildContext context) {
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

class _SummaryRow extends StatelessWidget {
  final String label;
  final Widget value;
  const _SummaryRow(this.label, this.value);

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
              child: Align(alignment: Alignment.centerRight, child: value)),
        ],
      );
}

class _FundingMethodTile extends StatelessWidget {
  const _FundingMethodTile({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.value,
    required this.selected,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final FundingMethod value;
  final bool selected;

  @override
  Widget build(BuildContext context) => Card(
        color: selected ? AppColors.infoBg : AppColors.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: BorderSide(
            color: selected ? AppColors.primary : AppColors.outline,
          ),
        ),
        child: RadioListTile<FundingMethod>(
          value: value,
          selected: selected,
          controlAffinity: ListTileControlAffinity.trailing,
          contentPadding: const EdgeInsets.symmetric(horizontal: 12),
          secondary: Icon(icon, color: AppColors.primary),
          title: Text(title),
          subtitle: Text(subtitle),
        ),
      );
}

class _WalletFlowError extends StatelessWidget {
  const _WalletFlowError({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(13),
        decoration: BoxDecoration(
          color: AppColors.errorBg,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AppColors.error.withValues(alpha: .22)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Icon(Icons.error_outline_rounded,
                color: AppColors.error, size: 19),
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
