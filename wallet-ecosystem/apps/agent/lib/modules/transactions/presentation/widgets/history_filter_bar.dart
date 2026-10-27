import 'package:flutter/material.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/payment_history_filter.dart';

/// Horizontal filter chip bar for the agent transaction history list.
class HistoryFilterBar extends StatelessWidget {
  final PaymentHistoryFilter currentFilter;
  final void Function(PaymentHistoryFilter) onFilterChanged;

  const HistoryFilterBar({
    super.key,
    required this.currentFilter,
    required this.onFilterChanged,
  });

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 52,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md,
          vertical: AppSpacing.xs,
        ),
        children: [
          _buildAllChip(),
          const SizedBox(width: AppSpacing.xs),
          ..._statusChips(),
        ],
      ),
    );
  }

  Widget _buildAllChip() {
    final isSelected = currentFilter.status == null;
    return Semantics(
      button: true,
      selected: isSelected,
      label: 'All filter',
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 2),
        child: FilterChip(
          label: const Text('All'),
          selected: isSelected,
          onSelected: (_) => onFilterChanged(
            const PaymentHistoryFilter(),
          ),
        ),
      ),
    );
  }

  List<Widget> _statusChips() {
    return PaymentStatus.values.map((status) {
      final isSelected = currentFilter.status == status;
      final label = _statusLabel(status);
      return Semantics(
        button: true,
        selected: isSelected,
        label: '$label filter',
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 2),
          child: FilterChip(
            label: Text(label),
            selected: isSelected,
            onSelected: (_) => onFilterChanged(
              currentFilter.copyWith(status: status),
            ),
          ),
        ),
      );
    }).toList();
  }

  String _statusLabel(PaymentStatus status) => switch (status) {
        PaymentStatus.pending => 'Pending',
        PaymentStatus.processing => 'Processing',
        PaymentStatus.completed => 'Completed',
        PaymentStatus.failed => 'Failed',
        PaymentStatus.reversed => 'Reversed',
        PaymentStatus.cancelled => 'Cancelled',
      };
}
