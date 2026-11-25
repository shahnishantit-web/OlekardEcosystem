import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:uuid/uuid.dart';
import '../transfer_providers.dart';
import '../../domain/entities/transfer_result.dart';
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

class SendMoneyScreen extends ConsumerStatefulWidget {
  const SendMoneyScreen({super.key});

  @override
  ConsumerState<SendMoneyScreen> createState() => _SendMoneyScreenState();
}

class _SendMoneyScreenState extends ConsumerState<SendMoneyScreen> {
  _Step _step = _Step.form;
  final _walletController = TextEditingController();
  final _amountController = TextEditingController();
  TransferPreview? _preview;
  String? _referenceNumber;
  bool _isLoading = false;
  String? _error;
  bool _scanning = false;
  final _guard = ScreenshotGuard();

  @override
  void initState() {
    super.initState();
    _guard.enable();
  }

  @override
  void dispose() {
    _walletController.dispose();
    _amountController.dispose();
    _guard.disable();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isOffline = ref.watch(isOfflineProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Send Money')),
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
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      children: [
        const WalletFlowHeading(
          step: 1,
          title: 'Send money',
          subtitle: 'Enter a wallet number or phone number to get started.',
        ),
        const SizedBox(height: 25),
        Text('Recipient', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 9),
        TextFormField(
          controller: _walletController,
          keyboardType: TextInputType.text,
          textInputAction: TextInputAction.next,
          decoration: InputDecoration(
            labelText: 'Wallet number or phone',
            prefixIcon: const Icon(Icons.person_outline_rounded),
            suffixIcon: IconButton(
              icon: const Icon(Icons.qr_code_scanner_rounded),
              tooltip: 'Scan recipient QR',
              onPressed: () => setState(() => _scanning = !_scanning),
            ),
          ),
        ),
        if (_scanning) ...[
          const SizedBox(height: 12),
          ClipRRect(
            borderRadius: BorderRadius.circular(18),
            child: SizedBox(
              height: 220,
              child: MobileScanner(
                onDetect: (capture) {
                  final barcode = capture.barcodes.firstOrNull;
                  if (barcode?.rawValue != null) {
                    _walletController.text = barcode!.rawValue!;
                    setState(() => _scanning = false);
                  }
                },
              ),
            ),
          ),
        ],
        const SizedBox(height: 22),
        Text('Transfer amount', style: Theme.of(context).textTheme.titleMedium),
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
          label: 'Review transfer',
          isLoading: _isLoading,
          onPressed:
              isOffline ? () => _showOfflineDialog(context) : _getPreview,
        ),
      ],
    );
  }

  Widget _buildPreview() {
    final p = _preview!;
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      children: [
        const WalletFlowHeading(
          step: 2,
          title: 'Review transfer',
          subtitle: 'Confirm the recipient and total debit.',
        ),
        const SizedBox(height: 24),
        WalletSummaryPanel(children: [
          _Row('Recipient', p.recipientName),
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
            subtitle: 'Enter your PIN to approve this transfer.',
          ),
          const SizedBox(height: 22),
          if (_isLoading)
            const Padding(
              padding: EdgeInsets.all(40),
              child: Center(child: CircularProgressIndicator()),
            )
          else
            PinPadWidget(onCompleted: _submitTransfer),
          TextButton(
            onPressed: () => setState(() => _step = _Step.preview),
            child: const Text('Back to review'),
          ),
        ],
      );

  Widget _buildResult() => SuccessView(
        title: 'Transfer Sent',
        referenceNumber: _referenceNumber ?? '',
        actionLabel: 'Back to Home',
        onAction: () => Navigator.of(context).pop(),
      );

  Future<void> _getPreview() async {
    final amount = double.tryParse(_amountController.text.trim());
    final wallet = _walletController.text.trim();
    if (amount == null || amount <= 0 || wallet.isEmpty) {
      setState(() => _error = 'Enter a valid amount and recipient');
      return;
    }
    setState(() {
      _isLoading = true;
      _error = null;
    });
    try {
      final preview =
          await ref.read(transferRepositoryProvider).previewTransfer(
                recipientWalletNumber: wallet,
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

  Future<void> _submitTransfer(String pin) async {
    final amount = double.parse(_amountController.text.trim());
    setState(() => _isLoading = true);
    try {
      final result = await ref.read(transferRepositoryProvider).executeTransfer(
            TransferRequestDto(
              idempotencyKey: const Uuid().v4(),
              recipientWalletNumber: _walletController.text.trim(),
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
