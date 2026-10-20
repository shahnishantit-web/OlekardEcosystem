import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/shared/widgets/primary_button.dart';
import 'package:wallet_agent/modules/cash_out/domain/entities/pickup_code_lookup.dart';
import 'package:wallet_agent/modules/cash_out/presentation/cash_out_providers.dart';

class PickupCodeScreen extends ConsumerStatefulWidget {
  const PickupCodeScreen({super.key});

  @override
  ConsumerState<PickupCodeScreen> createState() => _PickupCodeScreenState();
}

class _PickupCodeScreenState extends ConsumerState<PickupCodeScreen> {
  final _codeController = TextEditingController();
  final _scannerController = MobileScannerController();
  bool _isScanning = true;
  bool _isLoading = false;
  String? _errorMessage;
  bool _scannerActive = true;

  @override
  void dispose() {
    _codeController.dispose();
    _scannerController.dispose();
    super.dispose();
  }

  Future<void> _lookupCode(String code) async {
    if (code.trim().isEmpty) return;
    setState(() {
      _isLoading = true;
      _errorMessage = null;
      _scannerActive = false;
    });
    try {
      final lookup =
          await ref.read(cashOutRepositoryProvider).lookupPickupCode(code.trim());
      if (!mounted) return;
      context.push('/cash-out/confirm', extra: {
        'wallet_id': lookup.walletId,
        'customer_name': lookup.customerName,
        'masked_wallet_number': lookup.maskedWalletNumber,
        'amount_minor_units': lookup.amountMinorUnits,
        'currency_code': lookup.currencyCode,
        'pickup_code': lookup.pickupCode,
      });
    } on PickupCodeExpiredException {
      setState(() => _errorMessage = 'This pickup code has expired. Ask the customer to generate a new one.');
    } on PickupCodeRedeemedException {
      setState(() => _errorMessage = 'This pickup code has already been redeemed.');
    } catch (_) {
      setState(() => _errorMessage = 'Could not look up this code. Check it and try again.');
    } finally {
      if (mounted) {
        setState(() {
          _isLoading = false;
          _scannerActive = true;
        });
      }
    }
  }

  void _onQrDetected(BarcodeCapture capture) {
    if (!_scannerActive || _isLoading) return;
    final code = capture.barcodes.firstOrNull?.rawValue;
    if (code != null && code.isNotEmpty) {
      _lookupCode(code);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Cash Out — Scan or Enter Code'),
        actions: [
          TextButton(
            onPressed: () => setState(() => _isScanning = !_isScanning),
            child: Text(_isScanning ? 'Enter Manually' : 'Scan QR'),
          ),
        ],
      ),
      body: Column(
        children: [
          const OfflineBanner(),
          Expanded(
            child: _isScanning
                ? _QrScanView(
                    controller: _scannerController,
                    onDetected: _onQrDetected,
                    isLoading: _isLoading,
                    errorMessage: _errorMessage,
                  )
                : _ManualEntryView(
                    controller: _codeController,
                    isLoading: _isLoading,
                    errorMessage: _errorMessage,
                    onSubmit: () => _lookupCode(_codeController.text),
                  ),
          ),
        ],
      ),
    );
  }
}

class _QrScanView extends StatelessWidget {
  final MobileScannerController controller;
  final ValueChanged<BarcodeCapture> onDetected;
  final bool isLoading;
  final String? errorMessage;

  const _QrScanView({
    required this.controller,
    required this.onDetected,
    required this.isLoading,
    this.errorMessage,
  });

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        MobileScanner(controller: controller, onDetect: onDetected),
        // Viewfinder overlay
        Center(
          child: Container(
            width: 240,
            height: 240,
            decoration: BoxDecoration(
              border: Border.all(color: AppColors.primary, width: 3),
              borderRadius: BorderRadius.circular(AppSpacing.borderRadius),
            ),
          ),
        ),
        if (isLoading)
          Container(
            color: Colors.black45,
            child: const Center(child: CircularProgressIndicator()),
          ),
        if (errorMessage != null)
          Positioned(
            bottom: AppSpacing.lg,
            left: AppSpacing.md,
            right: AppSpacing.md,
            child: Card(
              color: AppColors.errorLight,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(
                  errorMessage!,
                  style: const TextStyle(color: AppColors.error),
                  textAlign: TextAlign.center,
                ),
              ),
            ),
          ),
        Positioned(
          top: AppSpacing.md,
          left: 0,
          right: 0,
          child: Center(
            child: Text(
              'Point the camera at the customer\'s QR code',
              style: AppTypography.bodySmall
                  .copyWith(color: Colors.white, shadows: [
                const Shadow(blurRadius: 4, color: Colors.black87),
              ]),
            ),
          ),
        ),
      ],
    );
  }
}

class _ManualEntryView extends StatelessWidget {
  final TextEditingController controller;
  final bool isLoading;
  final String? errorMessage;
  final VoidCallback onSubmit;

  const _ManualEntryView({
    required this.controller,
    required this.isLoading,
    this.errorMessage,
    required this.onSubmit,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SizedBox(height: AppSpacing.lg),
          const Text('Enter Pickup Code', style: AppTypography.titleMedium),
          const SizedBox(height: AppSpacing.sm),
          Text(
            'Ask the customer for their withdrawal pickup code.',
            style: AppTypography.bodyMedium
                .copyWith(color: AppColors.onSurfaceVariant),
          ),
          const SizedBox(height: AppSpacing.lg),
          Semantics(
            label: 'Pickup code',
            child: TextField(
              controller: controller,
              autofocus: true,
              keyboardType: TextInputType.text,
              textCapitalization: TextCapitalization.characters,
              decoration: const InputDecoration(
                labelText: 'Pickup Code',
                hintText: 'e.g. ABCD-1234',
                prefixIcon: Icon(Icons.qr_code),
              ),
              onSubmitted: (_) => onSubmit(),
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          if (errorMessage != null) ...[
            Text(errorMessage!, style: const TextStyle(color: AppColors.error)),
            const SizedBox(height: AppSpacing.md),
          ],
          PrimaryButton(
            label: 'Look Up Code',
            isLoading: isLoading,
            onPressed: onSubmit,
            icon: Icons.search,
          ),
        ],
      ),
    );
  }
}
