import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';
import '../../../notifications/presentation/notification_providers.dart';

export '../../../notifications/presentation/notification_providers.dart';

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifAsync = ref.watch(notificationListProvider(1));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          TextButton(
            onPressed: () => ref.read(notificationActionsProvider).markAllRead(),
            child: const Text('Mark all read'),
          ),
        ],
      ),
      body: notifAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 8),
        ),
        error: (e, _) => ErrorView(
          message: e.toString(),
          onRetry: () => ref.invalidate(notificationListProvider),
        ),
        data: (page) => page.data.isEmpty
            ? const Center(child: Text('No notifications'))
            : ListView.separated(
                itemCount: page.data.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  final n = page.data[i];
                  return ListTile(
                    leading: Icon(
                      n.isRead ? Icons.notifications_none : Icons.notifications,
                      color: n.isRead ? Colors.grey : AppColors.primary,
                    ),
                    title: Text(n.title, style: AppTypography.label),
                    subtitle: Text(n.body, maxLines: 2, overflow: TextOverflow.ellipsis),
                    tileColor: n.isRead ? null : AppColors.primary.withValues(alpha: 0.05),
                    onTap: () => ref.read(notificationActionsProvider).markRead(n.id),
                  );
                },
              ),
      ),
    );
  }
}
