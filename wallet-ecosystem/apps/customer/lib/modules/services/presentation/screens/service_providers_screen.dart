import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../services_providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';

class ServiceProvidersScreen extends ConsumerWidget {
  final String categoryId;

  const ServiceProvidersScreen({super.key, required this.categoryId});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final providersAsync = ref.watch(serviceProvidersProvider(categoryId));

    return Scaffold(
      appBar: AppBar(title: const Text('Providers')),
      body: providersAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 8),
        ),
        error: (e, _) => ErrorView(message: e.toString()),
        data: (providers) => providers.isEmpty
            ? const Center(child: Text('No services available.'))
            : ListView.separated(
                itemCount: providers.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  final p = providers[i];
                  return ListTile(
                    enabled: p.isActive,
                    leading: ClipRRect(
                      borderRadius: BorderRadius.circular(8),
                      child: p.logoUrl.isEmpty
                          ? const Icon(Icons.business, size: 40)
                          : CachedNetworkImage(
                              imageUrl: p.logoUrl,
                              width: 40,
                              height: 40,
                              placeholder: (_, __) =>
                                  const Icon(Icons.business),
                              errorWidget: (_, __, ___) =>
                                  const Icon(Icons.business),
                            ),
                    ),
                    title: Text(p.name, style: AppTypography.label),
                    subtitle: Text(
                      p.isActive ? p.description : 'Unavailable',
                      style: AppTypography.caption.copyWith(
                        color: p.isActive ? null : Colors.grey,
                      ),
                    ),
                    trailing: p.isActive
                        ? const Icon(Icons.chevron_right)
                        : const Chip(label: Text('Inactive')),
                    onTap: p.isActive
                        ? () => context.push('/services/purchase/${p.id}')
                        : null,
                  );
                },
              ),
      ),
    );
  }
}
