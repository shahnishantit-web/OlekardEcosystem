import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/core/connectivity/connectivity_service.dart';
import 'package:wallet_agent/modules/auth/presentation/auth_providers.dart';

class AgentDashboardHeader extends ConsumerWidget {
  const AgentDashboardHeader({super.key});

  String _maskedId(String id) {
    if (id.length <= 8) return '****';
    return '${id.substring(0, 4)}****${id.substring(id.length - 4)}';
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final authState = ref.watch(authNotifierProvider);
    final isOnline = ref.watch(connectivityProvider);
    final agent = authState.agent;

    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppSpacing.borderRadius),
      ),
      child: Row(
        children: [
          // Status indicator
          Semantics(
            label: isOnline ? 'Online' : 'Offline',
            child: Container(
              width: 10,
              height: 10,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: isOnline ? AppColors.success : AppColors.onSurfaceVariant,
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  agent?.fullName ?? 'Agent',
                  style: AppTypography.titleMedium.copyWith(
                    color: AppColors.textPrimary,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                if (agent != null)
                  Text(
                    'ID: ${_maskedId(agent.id)}',
                    style: AppTypography.bodySmall.copyWith(
                      color: AppColors.textSecondary,
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
