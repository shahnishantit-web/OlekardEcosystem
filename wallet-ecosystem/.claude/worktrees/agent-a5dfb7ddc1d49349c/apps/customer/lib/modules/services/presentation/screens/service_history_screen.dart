import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../services_providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/amount_display.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';

class ServiceHistoryScreen extends ConsumerStatefulWidget {
  const ServiceHistoryScreen({super.key});

  @override
  ConsumerState<ServiceHistoryScreen> createState() => _ServiceHistoryScreenState();
}

class _ServiceHistoryScreenState extends ConsumerState<ServiceHistoryScreen> {
  int _page = 1;
  final bool _isLoadingMore = false;
  final _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >= _scrollController.position.maxScrollExtent - 200 &&
        !_isLoadingMore) {
      final current = ref.read(servicePaymentHistoryProvider(_page));
      current.whenData((r) {
        if (r.hasNextPage) setState(() => _page++);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final historyAsync = ref.watch(servicePaymentHistoryProvider(_page));

    return Scaffold(
      appBar: AppBar(title: const Text('Payment History')),
      body: historyAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 8),
        ),
        error: (e, _) => ErrorView(
          message: e.toString(),
          onRetry: () => ref.invalidate(servicePaymentHistoryProvider(_page)),
        ),
        data: (result) => result.data.isEmpty
            ? const Center(child: Text('No payment history yet.'))
            : ListView.separated(
                controller: _scrollController,
                itemCount: result.data.length + (result.hasNextPage ? 1 : 0),
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  if (i >= result.data.length) {
                    return const Padding(
                      padding: EdgeInsets.all(AppSpacing.md),
                      child: Center(child: CircularProgressIndicator()),
                    );
                  }
                  final p = result.data[i];
                  return ListTile(
                    leading: const CircleAvatar(child: Icon(Icons.receipt_long)),
                    title: Text(p.providerName, style: AppTypography.label),
                    subtitle: Text(
                      p.createdAt.toLocal().toString().substring(0, 16),
                      style: AppTypography.caption,
                    ),
                    trailing: AmountDisplay(
                      minorUnits: p.amountMinorUnits,
                      currencyCode: p.currencyCode,
                    ),
                  );
                },
              ),
      ),
    );
  }
}
