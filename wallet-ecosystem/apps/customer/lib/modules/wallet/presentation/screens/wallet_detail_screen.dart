import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:share_plus/share_plus.dart';

import '../wallet_providers.dart';
import '../../../../core/security/screenshot_guard.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';
import '../widgets/wallet_components.dart';

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
      appBar: AppBar(
        title: const Text('My Wallet'),
        actions: [
          IconButton(
            tooltip: 'Refresh wallet',
            onPressed: () => ref.invalidate(walletProvider),
            icon: const Icon(Icons.refresh_rounded),
          ),
          const SizedBox(width: 4),
        ],
      ),
      body: walletAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 4),
        ),
        error: (error, _) => ErrorView(
          message: error.toString(),
          onRetry: () => ref.invalidate(walletProvider),
        ),
        data: (wallet) => RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(walletProvider);
            try {
              await ref.read(walletProvider.future);
            } catch (_) {
              // The provider's error state is displayed in the page.
            }
          },
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
            children: [
              const WalletSectionHeader(
                title: 'Your money, at a glance',
                subtitle: 'Manage your OleKard wallet securely.',
              ),
              const SizedBox(height: 18),
              WalletBalanceCard(wallet: wallet),
              const SizedBox(height: 28),
              const WalletSectionHeader(
                title: 'Wallet actions',
                subtitle: 'Choose what you would like to do.',
              ),
              const SizedBox(height: 14),
              GridView.count(
                crossAxisCount: 2,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                mainAxisSpacing: 12,
                crossAxisSpacing: 12,
                childAspectRatio: 1.13,
                children: [
                  WalletActionTile(
                    icon: Icons.north_east_rounded,
                    title: 'Send money',
                    subtitle: 'To a wallet or phone',
                    primary: true,
                    onTap: () => context.push('/send'),
                  ),
                  WalletActionTile(
                    icon: Icons.add_rounded,
                    title: 'Add money',
                    subtitle: 'Bank or agent cash-in',
                    onTap: () => context.push('/add-money'),
                  ),
                  WalletActionTile(
                    icon: Icons.qr_code_2_rounded,
                    title: 'Receive money',
                    subtitle: 'Share your wallet QR',
                    onTap: () => context.push('/receive'),
                  ),
                  WalletActionTile(
                    icon: Icons.south_west_rounded,
                    title: 'Withdraw',
                    subtitle: 'Cash out with an agent',
                    onTap: () => context.push('/withdraw'),
                  ),
                ],
              ),
              const SizedBox(height: 24),
              const WalletSectionHeader(title: 'Wallet details'),
              const SizedBox(height: 12),
              _WalletNumberCard(
                walletNumber: wallet.walletNumber,
                onCopy: () => _copyWalletNumber(wallet.walletNumber),
                onShare: () => _shareWalletNumber(wallet.walletNumber),
              ),
              const SizedBox(height: 14),
              Card(
                child: ListTile(
                  contentPadding:
                      const EdgeInsets.symmetric(horizontal: 16, vertical: 5),
                  leading: Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(
                      color: AppColors.primaryLight,
                      borderRadius: BorderRadius.circular(13),
                    ),
                    child: const Icon(Icons.receipt_long_outlined,
                        color: AppColors.primary),
                  ),
                  title: const Text('Pay bills and services'),
                  subtitle: const Text('Airtime, utilities and more'),
                  trailing: const Icon(Icons.chevron_right_rounded),
                  onTap: () => context.go('/services'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _copyWalletNumber(String walletNumber) async {
    await Clipboard.setData(ClipboardData(text: walletNumber));
    if (!mounted) return;
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        const SnackBar(content: Text('Wallet number copied')),
      );
  }

  Future<void> _shareWalletNumber(String walletNumber) async {
    await SharePlus.instance.share(ShareParams(text: walletNumber));
  }
}

class _WalletNumberCard extends StatelessWidget {
  const _WalletNumberCard({
    required this.walletNumber,
    required this.onCopy,
    required this.onShare,
  });

  final String walletNumber;
  final VoidCallback onCopy;
  final VoidCallback onShare;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.fromLTRB(16, 15, 10, 12),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: AppColors.outline),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Wallet number', style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 5),
            Row(
              children: [
                Expanded(
                  child: SelectableText(
                    walletNumber,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                IconButton(
                  tooltip: 'Copy wallet number',
                  onPressed: onCopy,
                  icon: const Icon(Icons.copy_rounded),
                  color: AppColors.primary,
                ),
                IconButton(
                  tooltip: 'Share wallet number',
                  onPressed: onShare,
                  icon: const Icon(Icons.ios_share_rounded),
                  color: AppColors.primary,
                ),
              ],
            ),
          ],
        ),
      );
}
