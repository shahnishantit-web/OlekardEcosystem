import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
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

    return Scaffold(
      appBar: AppBar(
        title: const Text('Services'),
        actions: [
          TextButton(
            onPressed: () => context.push('/services/funeral'),
            child: const Text('Funeral Assistance'),
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
          final filtered = categories.where((category) => category.name.toLowerCase().contains(_query.toLowerCase())).toList();
          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: TextField(
                  decoration: const InputDecoration(
                    hintText: 'Search services...',
                    prefixIcon: Icon(Icons.search),
                  ),
                  onChanged: (query) {
                    setState(() => _query = query);
                  },
                ),
              ),
              Expanded(
                child: filtered.isEmpty ? const Center(child: Text('No services found.')) : GridView.count(
                  crossAxisCount: 2,
                  padding: const EdgeInsets.all(AppSpacing.md),
                  mainAxisSpacing: AppSpacing.md,
                  crossAxisSpacing: AppSpacing.md,
                  children: filtered
                      .map((cat) => InkWell(
                            onTap: () => context.push('/services/${cat.id}'),
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
                                      cat.name,
                                      style: AppTypography.label,
                                      textAlign: TextAlign.center,
                                    ),
                                    Text(
                                      '${cat.providerCount} providers',
                                      style: AppTypography.caption.copyWith(color: Colors.grey),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ))
                      .toList(),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
