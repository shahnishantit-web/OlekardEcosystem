import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';
import '../services_providers.dart';
import '../../domain/entities/service_provider.dart';
import '../../../../core/connectivity/connectivity_service.dart';
import '../../../../core/security/screenshot_guard.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../modules/wallet/presentation/wallet_providers.dart';
import '../../../../shared/widgets/amount_display.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';
import '../../../../shared/widgets/pin_pad_widget.dart';
import '../../../../shared/widgets/primary_button.dart';
import '../../../../shared/widgets/success_view.dart';

enum _Step { form, preview, pin, result }

class ServicePurchaseScreen extends ConsumerStatefulWidget {
  final String providerId;
  const ServicePurchaseScreen({super.key, required this.providerId});

  @override
  ConsumerState<ServicePurchaseScreen> createState() => _ServicePurchaseScreenState();
}

class _ServicePurchaseScreenState extends ConsumerState<ServicePurchaseScreen> {
  _Step _step = _Step.form;
  final _amountController = TextEditingController();
  final Map<String, TextEditingController> _fieldControllers = {};
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
    for (final c in _fieldControllers.values) { c.dispose(); }
    _guard.disable();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final providerAsync = ref.watch(serviceProviderDetailProvider(widget.providerId));
    final isOffline = ref.watch(isOfflineProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Purchase Service')),
      body: providerAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 4),
        ),
        error: (e, _) => ErrorView(message: e.toString()),
        data: (provider) => _buildStep(provider, isOffline),
      ),
    );
  }

  Widget _buildStep(ServiceProvider provider, bool isOffline) {
    return switch (_step) {
      _Step.form => _buildForm(provider, isOffline),
      _Step.preview => _buildPreview(provider),
      _Step.pin => _buildPin(provider),
      _Step.result => _buildResult(),
    };
  }

  Widget _buildForm(ServiceProvider provider, bool isOffline) {
    // Build field controllers lazily
    for (final field in provider.requiredFields) {
      _fieldControllers.putIfAbsent(field.key, TextEditingController.new);
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(provider.name, style: AppTypography.title),
          const SizedBox(height: AppSpacing.md),
          ...provider.requiredFields.map((f) => Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.md),
                child: f.type == ServiceFieldType.select
                    ? DropdownButtonFormField<String>(
                        decoration: InputDecoration(labelText: f.label, hintText: f.hint),
                        items: f.options
                            ?.map((o) => DropdownMenuItem(value: o, child: Text(o)))
                            .toList(),
                        onChanged: (v) => _fieldControllers[f.key]?.text = v ?? '',
                      )
                    : TextFormField(
                        controller: _fieldControllers[f.key],
                        keyboardType: f.type == ServiceFieldType.phone
                            ? TextInputType.phone
                            : f.type == ServiceFieldType.numeric
                                ? TextInputType.number
                                : TextInputType.text,
                        decoration: InputDecoration(labelText: f.label, hintText: f.hint),
                      ),
              )),
          TextFormField(
            controller: _amountController,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'Amount'),
          ),
          if (_error != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(_error!, style: const TextStyle(color: AppColors.error)),
          ],
          const SizedBox(height: AppSpacing.lg),
          PrimaryButton(
            label: 'Continue',
            isLoading: _isLoading,
            onPressed: isOffline ? _showOfflineDialog : () => setState(() => _step = _Step.preview),
          ),
        ],
      ),
    );
  }

  Widget _buildPreview(ServiceProvider provider) {
    final amount = double.tryParse(_amountController.text.trim()) ?? 0;
    final amountMinor = (amount * 100).round();

    return Padding(
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Confirm: ${provider.name}', style: AppTypography.headline),
          const SizedBox(height: AppSpacing.lg),
          _Row('Amount', AmountDisplay(amountMinorUnits: amountMinor, currencyCode: provider.currencyCode)),
          _Row('Fee', AmountDisplay(amountMinorUnits: provider.feeMinorUnits, currencyCode: provider.currencyCode)),
          const Spacer(),
          PrimaryButton(
            label: 'Authorize with PIN',
            onPressed: () => setState(() => _step = _Step.pin),
          ),
        ],
      ),
    );
  }

  Widget _buildPin(ServiceProvider provider) => Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          children: [
            const Text('Enter PIN', style: AppTypography.title, textAlign: TextAlign.center),
            const SizedBox(height: AppSpacing.xl),
            if (_isLoading)
              const Center(child: CircularProgressIndicator())
            else
              PinPadWidget(
                                onCompleted: (pin) => _submit(provider, pin),
              ),
          ],
        ),
      );

  Widget _buildResult() => SuccessView(
        title: 'Payment Successful',
        referenceNumber: _referenceNumber ?? '',
        actionLabel: 'Done',
        onAction: () => Navigator.of(context).pop(),
      );

  Future<void> _submit(ServiceProvider provider, String pin) async {
    final amount = double.parse(_amountController.text.trim());
    setState(() => _isLoading = true);
    try {
      final fieldValues = Map.fromEntries(
        _fieldControllers.entries.map((e) => MapEntry(e.key, e.value.text)),
      );
      final result = await ref.read(serviceRepositoryProvider).purchaseService(
            ServicePurchaseRequestDto(
              idempotencyKey: const Uuid().v4(),
              providerId: widget.providerId,
              amountMinorUnits: (amount * 100).round(),
              fieldValues: fieldValues,
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

  void _showOfflineDialog() {
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
  final Widget value;
  const _Row(this.label, this.value);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text(label, style: AppTypography.label),
          value,
        ]),
      );
}
