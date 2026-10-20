import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../multi_currency_providers.dart';
import '../../domain/entities/currency_wallet.dart';

/// Same-currency internal transfer screen (US-MCW-004).
/// Moves funds between two of the customer's wallets in the same currency.
class SameCurrencyTransferScreen extends ConsumerStatefulWidget {
  const SameCurrencyTransferScreen({super.key});

  @override
  ConsumerState<SameCurrencyTransferScreen> createState() =>
      _SameCurrencyTransferScreenState();
}

class _SameCurrencyTransferScreenState
    extends ConsumerState<SameCurrencyTransferScreen> {
  final _amountController = TextEditingController();
  CurrencyWallet? _fromWallet;
  CurrencyWallet? _toWallet;
  bool _loading    = false;
  bool _submitted  = false;
  String? _error;

  @override
  void dispose() {
    _amountController.dispose();
    super.dispose();
  }

  List<CurrencyWallet> _walletsForCurrency(
      List<CurrencyWallet> all, String? currencyCode) {
    if (currencyCode == null) return [];
    return all.where((w) => w.currencyCode == currencyCode).toList();
  }

  Future<void> _submit(List<CurrencyWallet> wallets) async {
    final from   = _fromWallet;
    final to     = _toWallet;
    final amount = _amountController.text.trim();

    if (from == null || to == null || amount.isEmpty) return;
    if (from.id == to.id) {
      setState(() => _error = 'Source and destination wallets must be different.');
      return;
    }

    setState(() { _loading = true; _error = null; });

    try {
      await ref.read(sameCurrencyTransferProvider(
        SameCurrencyTransferParams(
          fromWalletId: from.id,
          toWalletId:   to.id,
          amount:       amount,
        ),
      ).future);
      if (mounted) setState(() { _submitted = true; _loading = false; });
    } catch (e) {
      if (mounted) setState(() { _error = e.toString(); _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    final walletsAsync = ref.watch(currencyWalletsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Transfer Between Wallets')),
      body: walletsAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error:   (e, _) => Center(
          child: Text('Failed to load wallets: $e',
              style: const TextStyle(color: Colors.red)),
        ),
        data: (wallets) {
          if (_submitted) {
            return _buildSuccess();
          }
          return _buildForm(wallets);
        },
      ),
    );
  }

  Widget _buildForm(List<CurrencyWallet> wallets) {
    final activeByCurrency = <String, List<CurrencyWallet>>{};
    for (final w in wallets.where((w) => w.isActive)) {
      activeByCurrency.putIfAbsent(w.currencyCode, () => []).add(w);
    }

    // Only currencies with >=2 wallets can do a same-currency transfer
    final eligibleCurrencies = activeByCurrency.entries
        .where((e) => e.value.length >= 2)
        .toList();

    if (eligibleCurrencies.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text(
            'You need at least two wallets in the same currency to transfer.',
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    final fromWallets = _fromWallet != null
        ? _walletsForCurrency(wallets, _fromWallet!.currencyCode)
        : <CurrencyWallet>[];
    final toWallets = fromWallets
        .where((w) => w.id != _fromWallet?.id)
        .toList();

    return SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Source Wallet',
              style: TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          DropdownButtonFormField<CurrencyWallet>(
            key: ValueKey(_fromWallet),
            initialValue: _fromWallet,
            hint: const Text('Select source wallet'),
            items: wallets
                .where((w) => w.isActive)
                .map((w) => DropdownMenuItem(
                      value: w,
                      child: Text('${w.currencyCode} — Balance: ${w.balance}'),
                    ))
                .toList(),
            onChanged: (w) => setState(() {
              _fromWallet = w;
              _toWallet   = null;
            }),
          ),
          const SizedBox(height: 16),
          const Text('Destination Wallet',
              style: TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          DropdownButtonFormField<CurrencyWallet>(
            key: ValueKey(_toWallet),
            initialValue: _toWallet,
            hint: const Text('Select destination wallet'),
            items: toWallets
                .map((w) => DropdownMenuItem(
                      value: w,
                      child: Text('${w.currencyCode} — Balance: ${w.balance}'),
                    ))
                .toList(),
            onChanged: toWallets.isEmpty
                ? null
                : (w) => setState(() => _toWallet = w),
          ),
          const SizedBox(height: 16),
          TextFormField(
            controller: _amountController,
            keyboardType:
                const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(
              labelText: 'Amount (${_fromWallet?.currencyCode ?? ''})',
              border: const OutlineInputBorder(),
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: const TextStyle(color: Colors.red)),
          ],
          const SizedBox(height: 24),
          ElevatedButton(
            onPressed: _loading ? null : () => _submit(wallets),
            child: _loading
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('Transfer'),
          ),
        ],
      ),
    );
  }

  Widget _buildSuccess() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.check_circle, color: Colors.green, size: 64),
            const SizedBox(height: 16),
            const Text('Transfer Successful',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Text(
              '${_amountController.text} ${_fromWallet?.currencyCode ?? ''} '
              'transferred to ${_toWallet?.currencyCode ?? ''} wallet.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Done'),
            ),
          ],
        ),
      ),
    );
  }
}
