import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:share_plus/share_plus.dart';
import '../wallet_providers.dart';
import '../../../../core/security/screenshot_guard.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/amount_display.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';

class WalletDetailScreen extends ConsumerStatefulWidget {
  const WalletDetailScreen({super.key});

  @override
  ConsumerState<WalletDetailScreen> createState() => _WalletDetailScreenState();
}

class _WalletDetailScreenState extends ConsumerState<WalletDetailScreen> {
  final _guard = ScreenshotGuard();

  @override
  void initState() {
    super.initState();
    _guard.enable();
  }

  @override
  void dispose() {
    _guard.disable();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final walletAsync = ref.watch(walletProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('My Wallet')),
      body: walletAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 4),
        ),
        error: (e, _) => ErrorView(message: e.toString()),
        data: (wallet) => Padding(
          padding: const EdgeInsets.all(AppSpacing.lg),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.lg),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Balance', style: AppTypography.caption),
                      AmountDisplay(
                        minorUnits: wallet.balanceMinorUnits,
                        currencyCode: wallet.currencyCode,
                        style: AppTypography.amount,
                      ),
                      const SizedBox(height: AppSpacing.md),
                      const Text('Wallet Number', style: AppTypography.caption),
                      Row(
                        children: [
                          Text(wallet.walletNumber, style: AppTypography.label),
                          IconButton(
                            icon: const Icon(Icons.copy_outlined, size: 18),
                            tooltip: 'Copy wallet number',
                            onPressed: () {
                              Clipboard.setData(ClipboardData(text: wallet.walletNumber));
                              ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(content: Text('Wallet number copied')),
                              );
                            },
                          ),
                          IconButton(
                            icon: const Icon(Icons.share_outlined, size: 18),
                            tooltip: 'Share wallet number',
                            onPressed: () => Share.share(wallet.walletNumber),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              ElevatedButton.icon(
                icon: const Icon(Icons.qr_code),
                label: const Text('Receive Money'),
                onPressed: () => context.push('/receive'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
