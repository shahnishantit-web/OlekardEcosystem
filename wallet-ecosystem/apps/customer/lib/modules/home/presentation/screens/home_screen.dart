import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../home_providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';
import '../../../../shared/widgets/transaction_list_tile.dart';
import '../../../events/presentation/widgets/event_image.dart';
import '../widgets/wallet_summary_card.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final summaryAsync = ref.watch(homeSummaryProvider);
    return Scaffold(
      appBar: AppBar(
        title: Image.asset('assets/branding/olekard-logo.png',
            height: 34,
            width: 124,
            fit: BoxFit.contain,
            alignment: Alignment.centerLeft),
        actions: [
          IconButton(
              icon: const Icon(Icons.notifications_none_rounded),
              onPressed: () => context.push('/home/notifications'),
              tooltip: 'Notifications')
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(homeSummaryProvider.future),
        child: summaryAsync.when(
          loading: () => const Padding(
              padding: EdgeInsets.all(AppSpacing.lg),
              child: ShimmerList(itemCount: 5)),
          error: (error, _) => ErrorView(
              message: error.toString(),
              onRetry: () => ref.invalidate(homeSummaryProvider)),
          data: (summary) => ListView(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
            children: [
              Text('Your wallet',
                  style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: 5),
              Text('A clear view of your money and activity.',
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(color: AppColors.textSecondary)),
              const SizedBox(height: 18),
              WalletSummaryCard(wallet: summary.primaryWallet),
              const SizedBox(height: 24),
              Text('Quick actions',
                  style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: 12),
              Row(children: [
                _Shortcut(
                    icon: Icons.add_rounded,
                    label: 'Add money',
                    onTap: () => context.push('/add-money')),
                _Shortcut(
                    icon: Icons.north_east_rounded,
                    label: 'Send',
                    onTap: () => context.push('/send')),
                _Shortcut(
                    icon: Icons.south_west_rounded,
                    label: 'Withdraw',
                    onTap: () => context.push('/withdraw')),
                _Shortcut(
                    icon: Icons.qr_code_2_rounded,
                    label: 'Receive',
                    onTap: () => context.push('/receive')),
              ]),
              const SizedBox(height: 24),
              Consumer(builder: (context, eventRef, _) {
                final eventsAsync = eventRef.watch(homeUpcomingEventsProvider);
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      Expanded(
                          child: Text('Upcoming events',
                              style: Theme.of(context).textTheme.titleLarge)),
                      TextButton(
                        onPressed: () => context.go('/events/catalogue'),
                        child: const Text('View all events'),
                      ),
                    ]),
                    const SizedBox(height: 8),
                    eventsAsync.when(
                      loading: () => const LinearProgressIndicator(),
                      error: (_, __) => _FeatureLink(
                        icon: Icons.event_available_rounded,
                        title: 'Explore events',
                        subtitle: 'Find events open for you',
                        wide: true,
                        onTap: () => context.go('/events/catalogue'),
                      ),
                      data: (events) => events.isEmpty
                          ? _FeatureLink(
                              icon: Icons.event_available_rounded,
                              title: 'Explore events',
                              subtitle: 'Find events open for you',
                              wide: true,
                              onTap: () => context.go('/events/catalogue'),
                            )
                          : Column(
                              children: events
                                  .map((event) => Padding(
                                        padding:
                                            const EdgeInsets.only(bottom: 8),
                                        child: _HomeEventCard(event: event),
                                      ))
                                  .toList(),
                            ),
                    ),
                  ],
                );
              }),
              const SizedBox(height: 26),
              Row(children: [
                Expanded(
                    child: Text('Recent activity',
                        style: Theme.of(context).textTheme.titleLarge)),
                TextButton(
                    style: TextButton.styleFrom(
                        minimumSize: const Size(48, 44),
                        padding: const EdgeInsets.symmetric(horizontal: 8)),
                    onPressed: () => context.go('/activity'),
                    child: const Text('See all')),
              ]),
              if (summary.recentTransactions.isEmpty)
                Padding(
                    padding: const EdgeInsets.symmetric(vertical: 18),
                    child: Text('Your recent wallet activity will appear here.',
                        style: Theme.of(context)
                            .textTheme
                            .bodyMedium
                            ?.copyWith(color: AppColors.textSecondary)))
              else
                ...summary.recentTransactions
                    .map((tx) => TransactionListTile(tx: tx)),
            ],
          ),
        ),
      ),
    );
  }
}

class _Shortcut extends StatelessWidget {
  const _Shortcut(
      {required this.icon, required this.label, required this.onTap});
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Expanded(
        child: Semantics(
            button: true,
            label: label,
            child: InkWell(
              onTap: onTap,
              borderRadius: BorderRadius.circular(16),
              child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: Column(children: [
                    Container(
                        width: 52,
                        height: 52,
                        decoration: const BoxDecoration(
                            color: AppColors.secondary, shape: BoxShape.circle),
                        child: Icon(icon, color: AppColors.primary, size: 24)),
                    const SizedBox(height: 8),
                    Text(label,
                        style: AppTypography.caption.copyWith(
                            fontWeight: FontWeight.w600,
                            color: AppColors.textSecondary),
                        textAlign: TextAlign.center),
                  ])),
            )),
      );
}

class _FeatureLink extends StatelessWidget {
  const _FeatureLink(
      {required this.icon,
      required this.title,
      required this.subtitle,
      required this.onTap,
      this.wide = false});
  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final bool wide;

  @override
  Widget build(BuildContext context) => Material(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(18),
          child: Container(
            constraints: BoxConstraints(minHeight: wide ? 92 : 128),
            padding: EdgeInsets.all(wide ? 16 : 14),
            decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(18),
                border: Border.all(color: AppColors.outline)),
            child: wide
                ? Row(
                    children: [
                      Container(
                        width: 46,
                        height: 46,
                        decoration: BoxDecoration(
                          color: AppColors.primaryLight,
                          borderRadius: BorderRadius.circular(14),
                        ),
                        child: Icon(icon, color: AppColors.primary, size: 25),
                      ),
                      const SizedBox(width: 13),
                      Expanded(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(title,
                                style: AppTypography.label
                                    .copyWith(color: AppColors.textPrimary)),
                            const SizedBox(height: 4),
                            Text(subtitle,
                                style: AppTypography.caption
                                    .copyWith(color: AppColors.textSecondary)),
                          ],
                        ),
                      ),
                      const SizedBox(width: 8),
                      const Icon(Icons.chevron_right_rounded,
                          color: AppColors.textSecondary),
                    ],
                  )
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Icon(icon, color: AppColors.primary, size: 25),
                      const SizedBox(height: 12),
                      Text(title,
                          style: AppTypography.label
                              .copyWith(color: AppColors.textPrimary)),
                      const SizedBox(height: 3),
                      Text(subtitle,
                          style: AppTypography.caption
                              .copyWith(color: AppColors.textSecondary)),
                    ],
                  ),
          ),
        ),
      );
}

class _HomeEventCard extends StatelessWidget {
  const _HomeEventCard({required this.event});
  final Map<String, dynamic> event;

  @override
  Widget build(BuildContext context) {
    final start = DateTime.tryParse(event['starts_at']?.toString() ?? '');
    final salesStart =
        DateTime.tryParse(event['sales_start_at']?.toString() ?? '');
    final city = event['venue_city']?.toString().trim() ?? '';
    final venue = event['venue_name']?.toString() ?? '';
    final opensLater = salesStart != null && salesStart.isAfter(DateTime.now());
    return Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => context.go('/events/catalogue'),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Row(
            children: [
              EventImage(
                imageUrl: event['image_url']?.toString(),
                eventId: event['id']?.toString(),
                width: 72,
                height: 72,
                label: 'Event image',
                iconSize: 24,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(event['title']?.toString() ?? 'Event',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.titleSmall),
                    if (start != null)
                      Text(_homeEventDate(start),
                          style:
                              Theme.of(context).textTheme.bodySmall?.copyWith(
                                    color: AppColors.textSecondary,
                                  )),
                    if (city.isNotEmpty || venue.isNotEmpty)
                      Text(
                          city.isEmpty
                              ? venue
                              : venue.isEmpty
                                  ? city
                                  : '$venue · $city',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style:
                              Theme.of(context).textTheme.bodySmall?.copyWith(
                                    color: AppColors.textSecondary,
                                  )),
                    Text(
                        opensLater
                            ? 'Booking opens ${_homeEventDate(salesStart)}'
                            : _homeEventPrice(event),
                        style: Theme.of(context).textTheme.labelLarge?.copyWith(
                              color: AppColors.primary,
                              fontWeight: FontWeight.w700,
                            ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis),
                  ],
                ),
              ),
              const Icon(Icons.chevron_right_rounded,
                  color: AppColors.textSecondary),
            ],
          ),
        ),
      ),
    );
  }
}

String _homeEventDate(DateTime value) {
  final local = value.toLocal();
  const months = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec'
  ];
  return '${months[local.month - 1]} ${local.day} · ${local.hour.toString().padLeft(2, '0')}:${local.minute.toString().padLeft(2, '0')}';
}

String _homeEventPrice(Map<String, dynamic> event) {
  final categories = (event['categories'] as List<dynamic>? ?? const [])
      .whereType<Map>()
      .where((category) =>
          category['allocation_type'] == 'paid' &&
          (category['price_minor'] as num? ?? 0) > 0)
      .toList();
  if (categories.isEmpty) return 'Free';
  categories.sort(
      (a, b) => (a['price_minor'] as num).compareTo(b['price_minor'] as num));
  final precision = (event['currency_precision'] as num?)?.toInt() ?? 2;
  var divisor = 1;
  for (var i = 0; i < precision; i++) {
    divisor *= 10;
  }
  final amount = (categories.first['price_minor'] as num) / divisor;
  return 'From ${event['currency_code'] ?? 'BWP'} ${amount.toStringAsFixed(precision)}';
}
