import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../domain/entities/fx_quote.dart';
import '../multi_currency_providers.dart';

/// Two-phase FX conversion screen.
/// Phase 1: enter amount + currencies → GET preview → POST quote
/// Phase 2: review locked quote → POST confirm
class FxConvertScreen extends ConsumerStatefulWidget {
  const FxConvertScreen({super.key});

  @override
  ConsumerState<FxConvertScreen> createState() => _FxConvertScreenState();
}

class _FxConvertScreenState extends ConsumerState<FxConvertScreen> {
  final _amountController = TextEditingController();
  String _fromCurrency = 'BWP';
  String _toCurrency   = 'USD';
  FxQuote? _quote;
  bool _loading    = false;
  bool _confirming = false;
  String? _error;

  @override
  void dispose() {
    _amountController.dispose();
    super.dispose();
  }

  Future<void> _requestQuote() async {
    final amount = _amountController.text.trim();
    if (amount.isEmpty) return;
    setState(() { _loading = true; _error = null; _quote = null; });
    try {
      final quote = await ref.read(
        fxQuoteRequestProvider(
          FxQuoteParams(
            fromCurrency: _fromCurrency,
            toCurrency:   _toCurrency,
            fromAmount:   amount,
          ),
        ).future,
      );
      setState(() => _quote = quote);
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      setState(() => _loading = false);
    }
  }

  Future<void> _confirm() async {
    if (_quote == null) return;
    setState(() { _confirming = true; _error = null; });
    try {
      await ref.read(fxConversionConfirmProvider(_quote!.id).future);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Conversion completed successfully!')),
        );
        Navigator.pop(context);
      }
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _confirming = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Convert Currency')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_quote == null) ...[
              _buildQuoteForm(),
            ] else ...[
              _buildQuoteReview(),
            ],
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: const TextStyle(color: Colors.red)),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildQuoteForm() {
    final currenciesAsync = ref.watch(activeCurrenciesProvider);
    final codes = currenciesAsync.whenData((list) => list.map((c) => c['code'] as String).toList()).value ?? ['BWP', 'USD'];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text('Get FX Quote', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
        const SizedBox(height: 16),
        DropdownButtonFormField<String>(
          key: ValueKey(_fromCurrency),
          initialValue: _fromCurrency,
          decoration: const InputDecoration(labelText: 'From Currency', border: OutlineInputBorder()),
          items: codes.map((c) => DropdownMenuItem(value: c, child: Text(c))).toList(),
          onChanged: (v) => setState(() => _fromCurrency = v ?? _fromCurrency),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          key: ValueKey(_toCurrency),
          initialValue: _toCurrency,
          decoration: const InputDecoration(labelText: 'To Currency', border: OutlineInputBorder()),
          items: codes.map((c) => DropdownMenuItem(value: c, child: Text(c))).toList(),
          onChanged: (v) => setState(() => _toCurrency = v ?? _toCurrency),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _amountController,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(labelText: 'Amount', border: OutlineInputBorder()),
        ),
        const SizedBox(height: 24),
        ElevatedButton(
          onPressed: _loading ? null : _requestQuote,
          child: _loading
              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
              : const Text('Get Quote'),
        ),
      ],
    );
  }

  Widget _buildQuoteReview() {
    final q = _quote!;
    final secondsLeft = q.expiresAt.difference(DateTime.now()).inSeconds;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text('Review Quote', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
        const SizedBox(height: 16),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                _Row(label: 'You send',    value: '${q.fromCurrencyCode} ${q.fromAmount}'),
                _Row(label: 'Fee',         value: '${q.fromCurrencyCode} ${q.feeAmount}'),
                _Row(label: 'You receive', value: '${q.toCurrencyCode} ${q.toAmount}'),
                _Row(label: 'Rate',        value: '1 ${q.fromCurrencyCode} = ${q.rate} ${q.toCurrencyCode}'),
                _Row(label: 'Expires in',  value: secondsLeft > 0 ? '${secondsLeft}s' : 'Expired'),
              ],
            ),
          ),
        ),
        const SizedBox(height: 24),
        if (secondsLeft > 0)
          ElevatedButton(
            onPressed: _confirming ? null : _confirm,
            child: _confirming
                ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                : const Text('Confirm Conversion'),
          ),
        if (secondsLeft <= 0)
          ElevatedButton(
            onPressed: () => setState(() => _quote = null),
            child: const Text('Quote Expired — Get New Quote'),
          ),
        const SizedBox(height: 8),
        OutlinedButton(
          onPressed: () => setState(() => _quote = null),
          child: const Text('Cancel'),
        ),
      ],
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(color: Colors.grey)),
          Text(value,  style: const TextStyle(fontWeight: FontWeight.w500)),
        ],
      ),
    );
  }
}
