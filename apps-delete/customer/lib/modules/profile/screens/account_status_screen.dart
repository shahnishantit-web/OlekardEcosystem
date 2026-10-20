import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../shared/widgets/badge.dart';
import '../../../shared/widgets/loading.dart';
import '../../../shared/widgets/error_state.dart';
import '../models/profile_models.dart';
import '../state/profile_state.dart';
import '../state/profile_state_notifier.dart';

/// US-USER-008 — Account status and history.
/// States: loading, active, suspended, pending-closure, closed; history list.
class AccountStatusScreen extends ConsumerStatefulWidget {
  const AccountStatusScreen({super.key});

  @override
  ConsumerState<AccountStatusScreen> createState() =>
      _AccountStatusScreenState();
}

class _AccountStatusScreenState extends ConsumerState<AccountStatusScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(profileStateNotifierProvider.notifier).loadAccountStatus();
    });
  }

  @override
  Widget build(BuildContext context) {
    final profileAsync = ref.watch(profileStateNotifierProvider);
    final loaded = profileAsync.valueOrNull is ProfileLoaded
        ? profileAsync.valueOrNull as ProfileLoaded
        : null;

    final isLoading = loaded?.isSubmitting ?? profileAsync.isLoading;
    final statusData = loaded?.accountStatus;
    final errorMessage = loaded?.errorMessage;

    return Scaffold(
      appBar: AppBar(title: const Text('Account Status')),
      body: SafeArea(
        child: switch (null) {
          _ when isLoading =>
            const Center(child: LoadingWidget(message: 'Loading status…')),
          _ when errorMessage != null => Center(
              child: ErrorStateWidget(
                message: errorMessage,
                onRetry: () => ref
                    .read(profileStateNotifierProvider.notifier)
                    .loadAccountStatus(),
              ),
            ),
          _ when statusData != null => _StatusBody(data: statusData),
          _ => const SizedBox.shrink(),
        },
      ),
    );
  }
}

class _StatusBody extends StatelessWidget {
  const _StatusBody({required this.data});

  final AccountStatusData data;

  BadgeVariant _statusVariant(String status) => switch (status) {
        'active' => BadgeVariant.success,
        'suspended' => BadgeVariant.error,
        'pending_closure' => BadgeVariant.warning,
        'closed' => BadgeVariant.neutral,
        _ => BadgeVariant.neutral,
      };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        // Current status
        Semantics(
          header: true,
          child: Text('Current status', style: theme.textTheme.titleMedium),
        ),
        const SizedBox(height: 12),
        AppBadge(
          label: data.status.replaceAll('_', ' ').toUpperCase(),
          variant: _statusVariant(data.status),
        ),
        // Obligations (if blocked)
        if (data.closureObligations != null &&
            data.closureObligations!.isNotEmpty) ...[
          const SizedBox(height: 16),
          Semantics(
            liveRegion: true,
            child: Text(
              'Outstanding obligations preventing closure:',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
          ...data.closureObligations!.map(
            (o) => Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text('• ${o.replaceAll('_', ' ')}',
                  style: theme.textTheme.bodySmall),
            ),
          ),
        ],
        const SizedBox(height: 32),
        // History
        Semantics(
          header: true,
          child: Text('Status history', style: theme.textTheme.titleMedium),
        ),
        const SizedBox(height: 12),
        if (data.history.isEmpty)
          Semantics(
            label: 'No status history available',
            child: Text(
              'No history available.',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          )
        else
          ...data.history.map((entry) => _HistoryTile(entry: entry)),
      ],
    );
  }
}

class _HistoryTile extends StatelessWidget {
  const _HistoryTile({required this.entry});

  final StatusHistoryEntry entry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dateStr =
        '${entry.createdAt.year}-${entry.createdAt.month.toString().padLeft(2, '0')}-${entry.createdAt.day.toString().padLeft(2, '0')}';

    return Semantics(
      label: '${entry.fromStatus} to ${entry.toStatus} on $dateStr'
          '${entry.reason != null ? ', reason: ${entry.reason}' : ''}',
      child: Card(
        margin: const EdgeInsets.only(bottom: 8),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Text(entry.fromStatus, style: theme.textTheme.bodySmall),
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 8),
                    child: Icon(Icons.arrow_forward, size: 14),
                  ),
                  Text(entry.toStatus,
                      style: theme.textTheme.bodySmall?.copyWith(
                        fontWeight: FontWeight.w600,
                      )),
                ],
              ),
              if (entry.reason != null) ...[
                const SizedBox(height: 4),
                Text(entry.reason!, style: theme.textTheme.bodySmall),
              ],
              const SizedBox(height: 4),
              Text(
                dateStr,
                style: theme.textTheme.labelSmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
