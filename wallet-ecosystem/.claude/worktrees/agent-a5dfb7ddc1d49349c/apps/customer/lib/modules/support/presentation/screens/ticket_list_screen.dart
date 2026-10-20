import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../support_providers.dart';
import '../../domain/entities/support_ticket.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';

class TicketListScreen extends ConsumerWidget {
  const TicketListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ticketsAsync = ref.watch(ticketsProvider(1));

    return Scaffold(
      appBar: AppBar(title: const Text('My Tickets')),
      floatingActionButton: FloatingActionButton.extended(
        icon: const Icon(Icons.add),
        label: const Text('New Inquiry'),
        onPressed: () => context.push('/support/new'),
      ),
      body: ticketsAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 6),
        ),
        error: (e, _) => ErrorView(message: e.toString()),
        data: (result) => result.data.isEmpty
            ? Center(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(Icons.inbox_outlined, size: 64, color: Colors.grey),
                    const SizedBox(height: AppSpacing.md),
                    const Text('No tickets yet.'),
                    const SizedBox(height: AppSpacing.md),
                    OutlinedButton(
                      onPressed: () => context.push('/support/new'),
                      child: const Text('Submit Inquiry'),
                    ),
                  ],
                ),
              )
            : ListView.separated(
                itemCount: result.data.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  final t = result.data[i];
                  return _TicketTile(ticket: t);
                },
              ),
      ),
    );
  }
}

class _TicketTile extends StatelessWidget {
  final SupportTicket ticket;
  const _TicketTile({required this.ticket});

  @override
  Widget build(BuildContext context) {
    final statusColor = switch (ticket.status) {
      TicketStatus.open => Colors.blue,
      TicketStatus.inProgress => Colors.orange,
      TicketStatus.resolved => Colors.green,
      TicketStatus.closed => Colors.grey,
    };

    return ListTile(
      leading: const Icon(Icons.support_agent),
      title: Text(ticket.subject, style: AppTypography.label),
      subtitle: Text(
        ticket.referenceNumber,
        style: AppTypography.caption,
      ),
      trailing: Chip(
        label: Text(ticket.status.name, style: const TextStyle(fontSize: 10)),
        backgroundColor: statusColor.withValues(alpha: 0.15),
        side: BorderSide(color: statusColor),
        padding: EdgeInsets.zero,
        labelPadding: const EdgeInsets.symmetric(horizontal: 6),
      ),
      onTap: () => context.push('/support/tickets/${ticket.id}'),
    );
  }
}
