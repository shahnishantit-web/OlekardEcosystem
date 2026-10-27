import 'package:flutter/material.dart';
import '../../domain/entities/payment_history_filter.dart';
import '../../domain/entities/transaction.dart';
import '../../../../../core/theme/design_tokens.dart';

class HistoryFilterBar extends StatelessWidget {
  final PaymentHistoryFilter currentFilter;
  final ValueChanged<PaymentHistoryFilter> onFilterChanged;

  const HistoryFilterBar({
    super.key,
    required this.currentFilter,
    required this.onFilterChanged,
  });

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 56,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
        children: [
          _StatusChip(
            label: 'All',
            selected: currentFilter.status == null,
            onTap: () =>
                onFilterChanged(currentFilter.copyWith(status: null)),
          ),
          ...PaymentStatus.values.map((s) => _StatusChip(
                label: _statusLabel(s),
                selected: currentFilter.status == s,
                onTap: () => onFilterChanged(currentFilter.copyWith(status: s)),
              )),
        ],
      ),
    );
  }

  static String _statusLabel(PaymentStatus s) => switch (s) {
        PaymentStatus.pending => 'Pending',
        PaymentStatus.processing => 'Processing',
        PaymentStatus.completed => 'Completed',
        PaymentStatus.failed => 'Failed',
        PaymentStatus.reversed => 'Reversed',
        PaymentStatus.cancelled => 'Cancelled',
      };
}

class _StatusChip extends StatelessWidget {
  final String label;
  final bool selected;
  final VoidCallback onTap;

  const _StatusChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: AppSpacing.sm),
      child: Semantics(
        button: true,
        selected: selected,
        label: '$label filter',
        child: SizedBox(
          height: 44,
          child: FilterChip(
            label: Text(label),
            selected: selected,
            onSelected: (_) => onTap(),
          ),
        ),
      ),
    );
  }
}
