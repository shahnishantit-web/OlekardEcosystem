import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/core/notifications/deep_link_handler.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/error_view.dart';
import 'package:wallet_agent/shared/widgets/loading_shimmer.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/modules/notifications/data/models/notification_dto.dart';
import 'package:wallet_agent/modules/notifications/domain/entities/app_notification.dart';
import 'package:wallet_agent/modules/notifications/presentation/notification_providers.dart';

final _notificationListProvider =
    FutureProvider.autoDispose<PaginatedResult<AppNotification>>((ref) async {
  final dio = createDio(null);
  final response =
      await dio.get<Map<String, dynamic>>('/v1/agent/notifications');
  final data = response.data!;
  final items = (data['data'] as List<dynamic>)
      .map((e) =>
          AppNotificationDto.fromJson(e as Map<String, dynamic>).toEntity())
      .toList();
  return PaginatedResult(
    data: items,
    currentPage: data['current_page'] as int,
    total: data['total'] as int,
    lastPage: data['last_page'] as int,
  );
});

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notificationsAsync = ref.watch(_notificationListProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          Semantics(
            label: 'Mark all notifications as read',
            button: true,
            child: TextButton(
              onPressed: () => _markAllRead(context, ref),
              child: const Text('Mark All Read'),
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          const OfflineBanner(),
          Expanded(
            child: notificationsAsync.when(
              loading: () => const ShimmerList(itemCount: 8),
              error: (e, _) => ErrorView(
                message: 'Failed to load notifications.',
                onRetry: () => ref.invalidate(_notificationListProvider),
              ),
              data: (page) => page.data.isEmpty
                  ? const Center(
                      child: Text('No notifications yet.'),
                    )
                  : ListView.builder(
                      itemCount: page.data.length,
                      itemBuilder: (ctx, i) => _NotificationTile(
                        notification: page.data[i],
                        onTap: () =>
                            _handleTap(ctx, ref, page.data[i]),
                      ),
                    ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _handleTap(
    BuildContext context,
    WidgetRef ref,
    AppNotification notification,
  ) async {
    // Mark as read silently
    try {
      await ref
          .read(notificationRepositoryProvider)
          .markRead(notification.id);
      ref.invalidate(_notificationListProvider);
      ref.invalidate(unreadCountProvider);
    } catch (_) {
      // Non-critical — navigate regardless
    }

    if (!context.mounted) return;
    final route = DeepLinkHandler.routeForNotificationType(
      notification.type.name,
    );
    if (route != null) {
      context.push(route);
    }
  }

  Future<void> _markAllRead(BuildContext context, WidgetRef ref) async {
    try {
      await ref.read(notificationRepositoryProvider).markAllRead();
      ref.invalidate(_notificationListProvider);
      ref.invalidate(unreadCountProvider);
    } catch (_) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Could not mark all as read.')),
        );
      }
    }
  }
}

class _NotificationTile extends StatelessWidget {
  final AppNotification notification;
  final VoidCallback onTap;

  const _NotificationTile({
    required this.notification,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: '${notification.title}: ${notification.body}',
      button: true,
      child: ListTile(
        onTap: onTap,
        leading: CircleAvatar(
          backgroundColor: notification.isRead
              ? AppColors.surfaceVariant
              : AppColors.primaryLight,
          child: Icon(
            _iconForType(notification.type),
            color: notification.isRead
                ? AppColors.onSurfaceVariant
                : AppColors.primary,
          ),
        ),
        title: Text(
          notification.title,
          style: notification.isRead
              ? AppTypography.bodyMedium
              : AppTypography.titleSmall,
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(notification.body,
                maxLines: 2, overflow: TextOverflow.ellipsis),
            Text(
              DateFormat('d MMM yyyy, HH:mm').format(notification.createdAt),
              style: AppTypography.bodySmall
                  .copyWith(color: AppColors.onSurfaceVariant),
            ),
          ],
        ),
        isThreeLine: true,
        trailing: notification.isRead
            ? null
            : Container(
                width: 8,
                height: 8,
                decoration: const BoxDecoration(
                  color: AppColors.primary,
                  shape: BoxShape.circle,
                ),
              ),
      ),
    );
  }

  IconData _iconForType(NotificationType type) {
    switch (type) {
      case NotificationType.floatApproved:
        return Icons.check_circle_outline;
      case NotificationType.floatRejected:
        return Icons.cancel_outlined;
      case NotificationType.commissionCredited:
        return Icons.monetization_on_outlined;
      case NotificationType.securityAlert:
        return Icons.security;
      case NotificationType.transactionCompleted:
        return Icons.receipt_long_outlined;
      default:
        return Icons.notifications_outlined;
    }
  }
}
