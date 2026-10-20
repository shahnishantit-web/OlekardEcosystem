import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../domain/entities/currency_wallet.dart';
import '../multi_currency_providers.dart';
import 'add_currency_wallet_screen.dart';

class CurrencyWalletListScreen extends ConsumerWidget {
  const CurrencyWalletListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final walletsAsync = ref.watch(currencyWalletsProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('My Currency Wallets'),
        actions: [
          IconButton(
            icon: const Icon(Icons.add),
            tooltip: 'Add currency wallet',
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute<void>(
                builder: (_) => const AddCurrencyWalletScreen(),
              ),
            ).then((_) => ref.invalidate(currencyWalletsProvider)),
          ),
        ],
      ),
      body: walletsAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (err, _) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('Failed to load wallets: $err'),
              const SizedBox(height: 12),
              ElevatedButton(
                onPressed: () => ref.invalidate(currencyWalletsProvider),
                child: const Text('Retry'),
              ),
            ],
          ),
        ),
        data: (wallets) => wallets.isEmpty
            ? Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Text('No currency wallets yet.'),
                    const SizedBox(height: 12),
                    ElevatedButton(
                      onPressed: () => Navigator.push(
                        context,
                        MaterialPageRoute<void>(
                          builder: (_) => const AddCurrencyWalletScreen(),
                        ),
                      ).then((_) => ref.invalidate(currencyWalletsProvider)),
                      child: const Text('Add Currency Wallet'),
                    ),
                  ],
                ),
              )
            : RefreshIndicator(
                onRefresh: () async => ref.invalidate(currencyWalletsProvider),
                child: ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: wallets.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (_, i) => _WalletTile(wallet: wallets[i]),
                ),
              ),
      ),
    );
  }
}

class _WalletTile extends StatelessWidget {
  const _WalletTile({required this.wallet});

  final CurrencyWallet wallet;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        leading: CircleAvatar(
          child: Text(
            wallet.symbol.isNotEmpty ? wallet.symbol : wallet.currencyCode[0],
            style: const TextStyle(fontWeight: FontWeight.bold),
          ),
        ),
        title: Text(wallet.currencyCode, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text('Balance: ${wallet.symbol}${wallet.balance}'),
        trailing: wallet.isActive
            ? null
            : const Chip(label: Text('Inactive'), backgroundColor: Colors.grey),
      ),
    );
  }
}
