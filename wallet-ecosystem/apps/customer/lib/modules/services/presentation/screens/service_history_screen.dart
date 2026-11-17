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
  ConsumerState<ServiceHistoryScreen> createState() =>
      _ServiceHistoryScreenState();
}

class _ServiceHistoryScreenState extends ConsumerState<ServiceHistoryScreen> {
  int _page = 1;

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
            : Column(
                children: [
                  Expanded(
                    child: ListView.separated(
                      itemCount: result.data.length,
                      separatorBuilder: (_, __) => const Divider(height: 1),
                      itemBuilder: (context, i) {
                        final payment = result.data[i];
                        return ListTile(
                          leading: const CircleAvatar(
                              child: Icon(Icons.receipt_long)),
                          title: Text(payment.providerName,
                              style: AppTypography.label),
                          subtitle: Text(
                            payment.createdAt
                                .toLocal()
                                .toString()
                                .substring(0, 16),
                            style: AppTypography.caption,
                          ),
                          trailing: AmountDisplay(
                            amountMinorUnits: payment.amountMinorUnits,
                            currencyCode: payment.currencyCode,
                          ),
                        );
                      },
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(
                        horizontal: AppSpacing.md, vertical: AppSpacing.xs),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        IconButton(
                          tooltip: 'Previous page',
                          onPressed:
                              _page > 1 ? () => setState(() => _page--) : null,
                          icon: const Icon(Icons.chevron_left),
                        ),
                        Text('Page $_page'),
                        IconButton(
                          tooltip: 'Next page',
                          onPressed: result.hasNextPage
                              ? () => setState(() => _page++)
                              : null,
                          icon: const Icon(Icons.chevron_right),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}
