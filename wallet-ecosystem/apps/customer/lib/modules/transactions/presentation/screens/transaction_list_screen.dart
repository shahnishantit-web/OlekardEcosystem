import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../transaction_providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';
import '../../../../shared/widgets/transaction_list_tile.dart';

class TransactionListScreen extends ConsumerStatefulWidget {
  const TransactionListScreen({super.key});

  @override
  ConsumerState<TransactionListScreen> createState() =>
      _TransactionListScreenState();
}

class _TransactionListScreenState extends ConsumerState<TransactionListScreen> {
  int _currentPage = 1;

  @override
  Widget build(BuildContext context) {
    final txAsync = ref.watch(transactionListProvider(_currentPage));

    return Scaffold(
      appBar: AppBar(title: const Text('Transactions')),
      body: txAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 10),
        ),
        error: (e, _) => ErrorView(
          message: e.toString(),
          onRetry: () => ref.invalidate(transactionListProvider),
        ),
        data: (page) => Column(
          children: [
            Expanded(
              child: ListView.separated(
                itemCount: page.data.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, i) => InkWell(
                  onTap: () =>
                      context.push('/home/transactions/${page.data[i].id}'),
                  child: TransactionListTile(tx: page.data[i]),
                ),
              ),
            ),
            if (page.hasNextPage)
              Padding(
                padding: const EdgeInsets.all(AppSpacing.sm),
                child: TextButton(
                  onPressed: () => setState(() => _currentPage++),
                  child: const Text('Load more'),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
