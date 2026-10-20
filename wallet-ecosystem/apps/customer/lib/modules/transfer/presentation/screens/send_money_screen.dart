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
      body: _buildStep(context, isOffline),
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
    return Padding(
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: TextFormField(
                  controller: _walletController,
                  decoration: const InputDecoration(labelText: 'Wallet Number or Phone'),
                ),
              ),
              IconButton(
                icon: const Icon(Icons.qr_code_scanner),
                tooltip: 'Scan QR',
                onPressed: () => setState(() => _scanning = true),
              ),
            ],
          ),
          if (_scanning)
            SizedBox(
              height: 200,
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
          const SizedBox(height: AppSpacing.md),
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
            onPressed: isOffline ? () => _showOfflineDialog(context) : _getPreview,
          ),
        ],
      ),
    );
  }

  Widget _buildPreview() {
    final p = _preview!;
    return Padding(
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Confirm Transfer', style: AppTypography.headline),
          const SizedBox(height: AppSpacing.lg),
          _Row('Recipient', p.recipientName),
          _RowAmount('Amount', p.amountMinorUnits, p.currencyCode),
          _RowAmount('Fee', p.feeMinorUnits, p.currencyCode),
          _RowAmount('Total Debit', p.amountMinorUnits + p.feeMinorUnits, p.currencyCode),
          const Spacer(),
          PrimaryButton(
            label: 'Confirm Transfer',
            onPressed: () => setState(() => _step = _Step.pin),
          ),
        ],
      ),
    );
  }

  Widget _buildPin() => Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          children: [
            const Text('Enter PIN', style: AppTypography.title, textAlign: TextAlign.center),
            const SizedBox(height: AppSpacing.xl),
            if (_isLoading)
              const Center(child: CircularProgressIndicator())
            else
              PinPadWidget(onCompleted: _submitTransfer),
          ],
        ),
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
    setState(() { _isLoading = true; _error = null; });
    try {
      final preview = await ref.read(transferRepositoryProvider).previewTransfer(
            recipientWalletNumber: wallet,
            amountMinorUnits: (amount * 100).round(),
            currencyCode: 'KES',
          );
      setState(() { _preview = preview; _step = _Step.preview; });
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

class _Row extends StatelessWidget {
  final String label;
  final String value;
  const _Row(this.label, this.value);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(label, style: AppTypography.label),
            Text(value, style: AppTypography.body),
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
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(label, style: AppTypography.label),
            AmountDisplay(amountMinorUnits: minorUnits, currencyCode: currencyCode),
          ],
        ),
      );
}
