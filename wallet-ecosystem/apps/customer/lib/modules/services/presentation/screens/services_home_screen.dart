import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../domain/entities/service_provider.dart';
import '../services_providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';

class ServicesHomeScreen extends ConsumerStatefulWidget {
  const ServicesHomeScreen({super.key});

  @override
  ConsumerState<ServicesHomeScreen> createState() => _ServicesHomeScreenState();
}

class _ServicesHomeScreenState extends ConsumerState<ServicesHomeScreen> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final categoriesAsync = ref.watch(serviceCategoriesProvider);
    final isWide = MediaQuery.sizeOf(context).width >= 600;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Services'),
        actions: isWide
            ? [
                TextButton(
                  onPressed: () => context.push('/events/tickets'),
                  child: const Text('My Event Tickets'),
                ),
                TextButton(
                  onPressed: () => context.push('/services/funeral'),
                  child: const Text('Funeral Assistance'),
                ),
              ]
            : [
                PopupMenuButton<String>(
                  tooltip: 'Services menu',
                  onSelected: (destination) => context.push(destination),
                  itemBuilder: (context) => const [
                    PopupMenuItem(
                      value: '/events/tickets',
                      child: Text('My Event Tickets'),
                    ),
                    PopupMenuItem(
                      value: '/services/funeral',
                      child: Text('Funeral Assistance'),
                    ),
                  ],
                ),
              ],
      ),
      body: categoriesAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 6),
        ),
        error: (e, _) => ErrorView(
          message: e.toString(),
          onRetry: () => ref.invalidate(serviceCategoriesProvider),
        ),
        data: (categories) {
          final query = _query.trim().toLowerCase();
          final filtered = categories
              .where((category) => category.name.toLowerCase().contains(query))
              .toList();
          final showFreeEventRegistration = query.isEmpty ||
              'free event registration'.contains(query) ||
              'events'.contains(query) ||
              'event registration'.contains(query) ||
              'registration'.contains(query);

          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: TextField(
                  decoration: const InputDecoration(
                    hintText: 'Search services...',
                    prefixIcon: Icon(Icons.search),
                  ),
                  onChanged: (query) => setState(() => _query = query),
                ),
              ),
              Expanded(
                child: filtered.isEmpty && !showFreeEventRegistration
                    ? const Center(child: Text('No services found.'))
                    : GridView.count(
                        crossAxisCount: 2,
                        padding: const EdgeInsets.all(AppSpacing.md),
                        mainAxisSpacing: AppSpacing.md,
                        crossAxisSpacing: AppSpacing.md,
                        children: [
                          if (showFreeEventRegistration)
                            _buildFreeEventRegistrationCard(),
                          ...filtered.map(_buildCategoryCard),
                        ],
                      ),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _buildFreeEventRegistrationCard() => InkWell(
        onTap: () => context.push('/events/catalogue'),
        borderRadius: BorderRadius.circular(12),
        child: Card(
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.event_available,
                    size: 32, color: AppColors.primary),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  'Events',
                  style: AppTypography.label,
                  textAlign: TextAlign.center,
                ),
                Text(
                  'Browse events, register free, or buy tickets',
                  style: AppTypography.caption.copyWith(color: Colors.grey),
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      );

  Widget _buildCategoryCard(ServiceCategory category) => InkWell(
        onTap: () => context.push('/services/${category.id}'),
        borderRadius: BorderRadius.circular(12),
        child: Card(
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.grid_view, size: 32, color: AppColors.primary),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  category.name,
                  style: AppTypography.label,
                  textAlign: TextAlign.center,
                ),
                Text(
                  '${category.providerCount} providers',
                  style: AppTypography.caption.copyWith(color: Colors.grey),
                ),
              ],
            ),
          ),
        ),
      );
}
