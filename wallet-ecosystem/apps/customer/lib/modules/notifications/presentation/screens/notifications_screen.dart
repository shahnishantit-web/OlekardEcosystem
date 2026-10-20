import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../notification_providers.dart';
import '../../domain/entities/notification.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';

class NotificationsScreen extends ConsumerStatefulWidget {
  const NotificationsScreen({super.key});

  @override
  ConsumerState<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends ConsumerState<NotificationsScreen> {
  int _page = 1;
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
    if (_scrollController.position.pixels >= _scrollController.position.maxScrollExtent - 200) {
      final current = ref.read(notificationsProvider(_page));
      current.whenData((r) {
        if (r.hasNextPage) setState(() => _page++);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final notifAsync = ref.watch(notificationsProvider(_page));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          TextButton(
            onPressed: () async {
              await ref.read(notificationRepositoryProvider).markAllAsRead();
              ref.invalidate(notificationsProvider(_page));
              ref.invalidate(unreadCountProvider);
            },
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
          onRetry: () => ref.invalidate(notificationsProvider(_page)),
        ),
        data: (result) => result.data.isEmpty
            ? const Center(child: Text('No notifications yet.'))
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
                  final n = result.data[i];
                  return _NotificationTile(
                    notification: n,
                    onTap: () => _handleTap(n),
                  );
                },
              ),
      ),
    );
  }

  Future<void> _handleTap(AppNotification n) async {
    if (!n.isRead) {
      await ref.read(notificationRepositoryProvider).markAsRead(n.id);
      ref.invalidate(notificationsProvider(_page));
      ref.invalidate(unreadCountProvider);
    }
    if (n.deepLink != null && mounted) {
      context.push(n.deepLink!);
    }
  }
}

class _NotificationTile extends StatelessWidget {
  final AppNotification notification;
  final VoidCallback onTap;
  const _NotificationTile({required this.notification, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final icon = switch (notification.type) {
      NotificationType.transaction => Icons.account_balance_wallet,
      NotificationType.security => Icons.security,
      NotificationType.marketing => Icons.campaign,
      NotificationType.system => Icons.info_outline,
    };

    return ListTile(
      tileColor: notification.isRead ? null : AppColors.primary.withValues(alpha: 0.05),
      leading: CircleAvatar(child: Icon(icon)),
      title: Text(
        notification.title,
        style: AppTypography.label.copyWith(
          fontWeight: notification.isRead ? FontWeight.normal : FontWeight.bold,
        ),
      ),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(notification.body, style: AppTypography.caption),
          const SizedBox(height: 2),
          Text(
            notification.createdAt.toLocal().toString().substring(0, 16),
            style: AppTypography.caption.copyWith(color: Colors.grey),
          ),
        ],
      ),
      isThreeLine: true,
      onTap: onTap,
    );
  }
}
