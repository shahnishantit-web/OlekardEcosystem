import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../multi_currency_providers.dart';

class AddCurrencyWalletScreen extends ConsumerStatefulWidget {
  const AddCurrencyWalletScreen({super.key});

  @override
  ConsumerState<AddCurrencyWalletScreen> createState() => _AddCurrencyWalletScreenState();
}

class _AddCurrencyWalletScreenState extends ConsumerState<AddCurrencyWalletScreen> {
  String? _selectedCode;
  bool _submitting = false;
  String? _error;

  Future<void> _submit() async {
    if (_selectedCode == null) return;
    setState(() { _submitting = true; _error = null; });
    try {
      await ref.read(createCurrencyWalletProvider(_selectedCode!).future);
      if (mounted) Navigator.pop(context);
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final currenciesAsync = ref.watch(activeCurrenciesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Add Currency Wallet')),
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: currenciesAsync.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (err, _) => Center(child: Text('Failed to load currencies: $err')),
          data: (currencies) => Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Select a currency to create a new wallet pocket.',
                style: TextStyle(fontSize: 16),
              ),
              const SizedBox(height: 24),
              DropdownButtonFormField<String>(
                key: ValueKey(_selectedCode),
                initialValue: _selectedCode,
                decoration: const InputDecoration(
                  labelText: 'Currency',
                  border: OutlineInputBorder(),
                ),
                items: currencies
                    .map(
                      (c) => DropdownMenuItem<String>(
                        value: c['code'] as String,
                        child: Text('${c['code']} — ${c['name']}'),
                      ),
                    )
                    .toList(),
                onChanged: (v) => setState(() => _selectedCode = v),
              ),
              if (_error != null) ...[
                const SizedBox(height: 12),
                Text(_error!, style: const TextStyle(color: Colors.red)),
              ],
              const SizedBox(height: 24),
              ElevatedButton(
                onPressed: _submitting || _selectedCode == null ? null : _submit,
                child: _submitting
                    ? const SizedBox(
                        width: 20, height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Create Wallet'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
