import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../support_providers.dart';
import '../../domain/entities/support_ticket.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/amount_display.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';

class DisputeListScreen extends ConsumerWidget {
  const DisputeListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final disputesAsync = ref.watch(disputesProvider(1));

    return Scaffold(
      appBar: AppBar(title: const Text('My Disputes')),
      floatingActionButton: FloatingActionButton.extended(
        icon: const Icon(Icons.add),
        label: const Text('Raise Dispute'),
        onPressed: () => context.push('/support/disputes/new'),
      ),
      body: disputesAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 5),
        ),
        error: (e, _) => ErrorView(message: e.toString()),
        data: (result) => result.data.isEmpty
            ? const Center(child: Text('No disputes filed.'))
            : ListView.separated(
                itemCount: result.data.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  final d = result.data[i];
                  final statusColor = switch (d.status) {
                    DisputeStatus.open => Colors.blue,
                    DisputeStatus.underReview => Colors.orange,
                    DisputeStatus.resolved => Colors.green,
                    DisputeStatus.closed => Colors.grey,
                  };
                  return ListTile(
                    leading: const Icon(Icons.report_problem_outlined),
                    title: Text(d.reason, style: AppTypography.label),
                    subtitle: Text(d.referenceNumber, style: AppTypography.caption),
                    trailing: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        AmountDisplay(
                          amountMinorUnits: d.disputedAmountMinorUnits,
                          currencyCode: d.currencyCode,
                        ),
                        Chip(
                          label: Text(d.status.name, style: const TextStyle(fontSize: 10)),
                          backgroundColor: statusColor.withValues(alpha: 0.15),
                          side: BorderSide(color: statusColor),
                          padding: EdgeInsets.zero,
                          labelPadding: const EdgeInsets.symmetric(horizontal: 4),
                        ),
                      ],
                    ),
                    onTap: () => context.push('/support/disputes/${d.id}'),
                  );
                },
              ),
      ),
    );
  }
}
