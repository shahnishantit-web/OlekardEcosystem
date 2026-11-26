import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:uuid/uuid.dart';

import '../../../../core/di/providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../widgets/event_category_style.dart';
import '../widgets/event_image.dart';
import 'customer_event_checkout_screen.dart';
import 'free_event_registration_screen.dart';

final customerEventCatalogueDioProvider =
    Provider<Dio>((ref) => ref.watch(apiClientProvider).dio);

class CustomerEventCatalogueScreen extends ConsumerStatefulWidget {
  const CustomerEventCatalogueScreen({super.key});

  @override
  ConsumerState<CustomerEventCatalogueScreen> createState() =>
      _CustomerEventCatalogueScreenState();
}

class _CustomerEventCatalogueScreenState
    extends ConsumerState<CustomerEventCatalogueScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _events = [];
  String _filter = 'All';
  String? _location;
  String _search = '';
  String _selectedCategory = 'all';
  bool _showAll = false;
  bool _showMoreEventsAsCards = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final response = await ref
          .read(customerEventCatalogueDioProvider)
          .get<Object?>('/v1/events/customer/catalogue');
      final body = response.data as Map<String, dynamic>;
      final rows = body['data'] as List<dynamic>? ?? const [];
      _events = rows.whereType<Map<String, dynamic>>().toList();
    } on DioException catch (error) {
      _error = error.response?.data is Map
          ? (error.response!.data as Map)['message']?.toString()
          : null;
      _error ??= 'Could not load events. Check your connection and try again.';
    } catch (_) {
      _error = 'Could not load events. Please try again.';
    }
    if (mounted) setState(() => _loading = false);
  }

  @override
  Widget build(BuildContext context) {
    final locations = _events
        .map((event) => _eventLocation(event))
        .where((value) => value.isNotEmpty)
        .toSet()
        .toList()
      ..sort();
    final eventCategories = _events.map(_eventCategory).toSet().toList()
      ..sort();
    final visibleEvents = _events.where(_matches).toList()
      ..sort((a, b) => (DateTime.tryParse(a['starts_at']?.toString() ?? '') ??
              DateTime(9999))
          .compareTo(DateTime.tryParse(b['starts_at']?.toString() ?? '') ??
              DateTime(9999)));
    final resultsMode = _showAll ||
        _search.trim().isNotEmpty ||
        _selectedCategory != 'all' ||
        _filter != 'All' ||
        _location != null;
    final carouselEvents = visibleEvents.take(3).toList();
    final moreEvents = visibleEvents.skip(3).toList();

    return Scaffold(
      appBar: AppBar(
        title: const Text('Events'),
        actions: [
          TextButton.icon(
            onPressed: () => context.push('/events/tickets'),
            icon: const Icon(Icons.confirmation_number_outlined),
            label: const Text('My Tickets'),
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      const Icon(Icons.cloud_off_outlined,
                          size: 40, color: AppColors.textSecondary),
                      const SizedBox(height: 12),
                      Text(_error!, textAlign: TextAlign.center),
                      const SizedBox(height: 8),
                      TextButton.icon(
                        onPressed: _load,
                        icon: const Icon(Icons.refresh),
                        label: const Text('Try again'),
                      ),
                    ]),
                  ),
                )
              : Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 8, 16, 10),
                      child: TextField(
                        decoration: InputDecoration(
                          hintText: 'Search events',
                          prefixIcon: const Icon(Icons.search),
                          suffixIcon: _search.isEmpty
                              ? null
                              : IconButton(
                                  tooltip: 'Clear search',
                                  onPressed: () => setState(() {
                                    _search = '';
                                    _showAll = false;
                                  }),
                                  icon: const Icon(Icons.close),
                                ),
                        ),
                        onChanged: (value) => setState(() {
                          _search = value;
                          _showAll = false;
                        }),
                      ),
                    ),
                    SizedBox(
                      height: 82,
                      child: ListView(
                        scrollDirection: Axis.horizontal,
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        children: [
                          _EventCategoryTile(
                            label: 'All events',
                            icon: Icons.grid_view_rounded,
                            selected: _selectedCategory == 'all',
                            onTap: () => setState(() {
                              _selectedCategory = 'all';
                              _showAll = false;
                            }),
                          ),
                          for (final category in eventCategories)
                            _EventCategoryTile(
                              label: _categoryLabel(category),
                              icon: _categoryIcon(category),
                              selected: _selectedCategory == category,
                              onTap: () => setState(() {
                                _selectedCategory = category;
                                _showAll = false;
                              }),
                            ),
                        ],
                      ),
                    ),
                    SizedBox(
                      height: 52,
                      child: ListView(
                        scrollDirection: Axis.horizontal,
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        children: [
                          for (final label in const [
                            'All',
                            'This Weekend',
                            'Free',
                            'Paid'
                          ])
                            Padding(
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 4),
                              child: FilterChip(
                                label: Text(label),
                                selected: _filter == label,
                                onSelected: (_) => setState(() {
                                  _filter = label;
                                  _showAll = false;
                                }),
                              ),
                            ),
                          if (locations.length > 1)
                            Padding(
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 4),
                              child: PopupMenuButton<String?>(
                                onSelected: (value) => setState(() {
                                  _location = value;
                                  _showAll = false;
                                }),
                                itemBuilder: (_) => [
                                  const PopupMenuItem<String?>(
                                      value: null,
                                      child: Text('All locations')),
                                  ...locations.map((value) =>
                                      PopupMenuItem<String?>(
                                          value: value, child: Text(value))),
                                ],
                                child: Chip(
                                  avatar: const Icon(Icons.place_outlined,
                                      size: 18),
                                  label: Text(_location ?? 'Location'),
                                ),
                              ),
                            ),
                        ],
                      ),
                    ),
                    Expanded(
                      child: RefreshIndicator(
                        onRefresh: _load,
                        child: ListView(
                          physics: const AlwaysScrollableScrollPhysics(),
                          padding: const EdgeInsets.fromLTRB(16, 10, 16, 28),
                          children: [
                            if (visibleEvents.isEmpty)
                              const Padding(
                                padding: EdgeInsets.only(top: 72),
                                child: Column(children: [
                                  Icon(Icons.event_busy_outlined,
                                      size: 42, color: AppColors.textSecondary),
                                  SizedBox(height: 12),
                                  Text('No events match your filters.'),
                                ]),
                              )
                            else if (resultsMode) ...[
                              _ResultsHeading(
                                title: _search.trim().isNotEmpty
                                    ? 'Search results'
                                    : _selectedCategory == 'all'
                                        ? 'All events'
                                        : _categoryLabel(_selectedCategory),
                                count: visibleEvents.length,
                                cardsSelected: _showMoreEventsAsCards,
                                onViewChanged: (cards) => setState(
                                    () => _showMoreEventsAsCards = cards),
                              ),
                              const SizedBox(height: 12),
                              if (_showMoreEventsAsCards)
                                ...visibleEvents.map((event) => Padding(
                                      padding:
                                          const EdgeInsets.only(bottom: 12),
                                      child: _EventCarouselCard(
                                        event: event,
                                        showDateBadge: true,
                                        onTap: () => _openEvent(event),
                                      ),
                                    ))
                              else
                                ...visibleEvents.map((event) => _EventRow(
                                      event: event,
                                      onTap: () => _openEvent(event),
                                    )),
                            ] else ...[
                              Row(
                                children: [
                                  Expanded(
                                    child: Text('Upcoming events',
                                        style: Theme.of(context)
                                            .textTheme
                                            .titleLarge),
                                  ),
                                  if (visibleEvents.length > 3)
                                    TextButton(
                                      onPressed: () =>
                                          setState(() => _showAll = true),
                                      child: const Text('See all'),
                                    ),
                                ],
                              ),
                              const SizedBox(height: 10),
                              SizedBox(
                                height: 340 +
                                    ((MediaQuery.textScalerOf(context)
                                                    .scale(14) -
                                                14) *
                                            12)
                                        .clamp(0, 140),
                                child: ListView.separated(
                                  scrollDirection: Axis.horizontal,
                                  clipBehavior: Clip.none,
                                  itemCount: carouselEvents.length,
                                  separatorBuilder: (_, __) =>
                                      const SizedBox(width: 12),
                                  itemBuilder: (context, index) {
                                    final event = carouselEvents[index];
                                    return SizedBox(
                                      width: MediaQuery.sizeOf(context).width <
                                              380
                                          ? MediaQuery.sizeOf(context).width -
                                              72
                                          : 280,
                                      child: _EventCarouselCard(
                                        event: event,
                                        onTap: () => _openEvent(event),
                                      ),
                                    );
                                  },
                                ),
                              ),
                              if (moreEvents.isNotEmpty) ...[
                                const SizedBox(height: 24),
                                _ResultsHeading(
                                  title: 'More events',
                                  count: moreEvents.length,
                                  cardsSelected: _showMoreEventsAsCards,
                                  onViewChanged: (cards) => setState(
                                      () => _showMoreEventsAsCards = cards),
                                ),
                                const SizedBox(height: 10),
                                if (_showMoreEventsAsCards)
                                  ...moreEvents.map((event) => Padding(
                                        padding:
                                            const EdgeInsets.only(bottom: 12),
                                        child: _EventCarouselCard(
                                          event: event,
                                          showDateBadge: true,
                                          onTap: () => _openEvent(event),
                                        ),
                                      ))
                                else
                                  ...moreEvents.map((event) => _EventRow(
                                        event: event,
                                        onTap: () => _openEvent(event),
                                      )),
                              ],
                            ],
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
    );
  }

  bool _matches(Map<String, dynamic> event) {
    final categories = _categories(event);
    final isPaid = categories.any(_isPaidCategory);
    final start = DateTime.tryParse(event['starts_at']?.toString() ?? '');
    final query = _search.trim().toLowerCase();
    final searchable = [
      event['title'],
      event['venue_name'],
      event['venue_city'],
      event['venue_address'],
      event['organiser_name'],
      _categoryLabel(_eventCategory(event)),
    ].whereType<Object>().join(' ').toLowerCase();
    if (query.isNotEmpty && !searchable.contains(query)) return false;
    if (_location != null && _eventLocation(event) != _location) {
      return false;
    }
    if (_selectedCategory != 'all' &&
        _eventCategory(event) != _selectedCategory) {
      return false;
    }
    if (_filter == 'Free' && isPaid) return false;
    if (_filter == 'Paid' && !isPaid) return false;
    if (_filter == 'This Weekend' &&
        (start == null || !_isThisWeekend(start))) {
      return false;
    }
    return true;
  }

  bool _isThisWeekend(DateTime value) {
    final now = DateTime.now();
    final local = value.toLocal();
    final date = DateTime(local.year, local.month, local.day);
    final today = DateTime(now.year, now.month, now.day);
    final daysUntilSunday = (DateTime.sunday - today.weekday) % 7;
    final sunday = today.add(Duration(days: daysUntilSunday));
    final daysUntilSaturday = (DateTime.saturday - today.weekday) % 7;
    final saturday = today.add(Duration(days: daysUntilSaturday));
    final start = today.weekday == DateTime.sunday ? today : saturday;
    return !date.isBefore(start) && !date.isAfter(sunday);
  }

  List<Map<String, dynamic>> _categories(Map<String, dynamic> event) =>
      (event['categories'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();

  String _eventCategory(Map<String, dynamic> event) {
    const valid = {
      'community',
      'music',
      'sports',
      'club_game',
      'business',
      'arts',
      'family',
      'other'
    };
    final value = event['event_category']?.toString().toLowerCase();
    return valid.contains(value) ? value! : 'other';
  }

  String _categoryLabel(String value) => switch (value) {
        'community' => 'Community',
        'music' => 'Music',
        'sports' => 'Sports',
        'club_game' => 'Club game',
        'business' => 'Business',
        'arts' => 'Arts & culture',
        'family' => 'Family',
        'other' => 'Other',
        _ => 'All events',
      };

  IconData _categoryIcon(String value) => switch (value) {
        'community' => Icons.groups_outlined,
        'music' => Icons.music_note_outlined,
        'sports' => Icons.sports_soccer_outlined,
        'club_game' => Icons.emoji_events_outlined,
        'business' => Icons.work_outline_rounded,
        'arts' => Icons.palette_outlined,
        'family' => Icons.family_restroom_outlined,
        _ => Icons.event_outlined,
      };

  bool _isPaidCategory(Map category) =>
      category['allocation_type'] == 'paid' &&
      (category['price_minor'] as num? ?? 0) > 0;

  String _eventLocation(Map<String, dynamic> event) {
    final city = event['venue_city']?.toString().trim() ?? '';
    return city.isNotEmpty
        ? city
        : (event['venue_name']?.toString().trim() ?? '');
  }

  void _openEvent(Map<String, dynamic> event) {
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => _CustomerEventDetailsScreen(
        event: event,
        dio: ref.read(customerEventCatalogueDioProvider),
      ),
    ));
  }
}

class _EventCategoryTile extends StatelessWidget {
  const _EventCategoryTile(
      {required this.label,
      required this.icon,
      required this.selected,
      required this.onTap});
  final String label;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final categoryId = label == 'Arts & culture'
        ? 'arts'
        : label.toLowerCase().replaceAll(' ', '_');
    final style = EventCategoryStyle.resolve(
      categoryId == 'all_events' ? 'other' : categoryId,
      Theme.of(context).brightness,
    );
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
      child: Material(
        color: style.background,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Container(
            constraints: const BoxConstraints(minWidth: 82),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: selected
                    ? AppColors.primary
                    : style.foreground.withValues(alpha: .28),
                width: selected ? 2 : 1,
              ),
            ),
            child: Stack(alignment: Alignment.center, children: [
              Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(icon, size: 21, color: style.foreground),
                  const SizedBox(height: 3),
                  Text(label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.labelSmall?.copyWith(
                            color: style.foreground,
                            fontWeight:
                                selected ? FontWeight.w700 : FontWeight.w500,
                          )),
                ],
              ),
              if (selected)
                const Positioned(
                  right: 0,
                  top: 0,
                  child: Icon(Icons.check_circle,
                      size: 15, color: AppColors.primary),
                ),
            ]),
          ),
        ),
      ),
    );
  }
}

class _SectionHeading extends StatelessWidget {
  const _SectionHeading({required this.title, this.count});
  final String title;
  final int? count;

  @override
  Widget build(BuildContext context) => Row(children: [
        Expanded(
            child: Text(title, style: Theme.of(context).textTheme.titleLarge)),
        if (count != null)
          Text('$count',
              style: Theme.of(context)
                  .textTheme
                  .labelMedium
                  ?.copyWith(color: AppColors.textSecondary)),
      ]);
}

class _ResultsHeading extends StatelessWidget {
  const _ResultsHeading({
    required this.title,
    required this.count,
    required this.cardsSelected,
    required this.onViewChanged,
  });

  final String title;
  final int count;
  final bool cardsSelected;
  final ValueChanged<bool> onViewChanged;

  @override
  Widget build(BuildContext context) => Row(children: [
        Expanded(child: _SectionHeading(title: title, count: count)),
        const SizedBox(width: 8),
        SegmentedButton<bool>(
          showSelectedIcon: false,
          style: ButtonStyle(
            visualDensity: VisualDensity.compact,
            padding: const WidgetStatePropertyAll(
                EdgeInsets.symmetric(horizontal: 9, vertical: 5)),
          ),
          segments: const [
            ButtonSegment(
                value: false,
                icon: Icon(Icons.view_list_rounded, size: 17),
                label: Text('List')),
            ButtonSegment(
                value: true,
                icon: Icon(Icons.grid_view_rounded, size: 17),
                label: Text('Cards')),
          ],
          selected: {cardsSelected},
          onSelectionChanged: (selection) => onViewChanged(selection.first),
        ),
      ]);
}

class _EventCarouselCard extends StatelessWidget {
  const _EventCarouselCard({
    required this.event,
    required this.onTap,
    this.showDateBadge = false,
  });
  final Map<String, dynamic> event;
  final VoidCallback onTap;
  final bool showDateBadge;

  @override
  Widget build(BuildContext context) {
    final start = DateTime.tryParse(event['starts_at']?.toString() ?? '');
    final venue = event['venue_name']?.toString() ?? '';
    final city = event['venue_city']?.toString().trim() ?? '';
    final localStart = start?.toLocal();
    final salesStart =
        DateTime.tryParse(event['sales_start_at']?.toString() ?? '');
    final opensLater = salesStart != null && salesStart.isAfter(DateTime.now());
    final sponsor = (event['sponsors'] as List<dynamic>? ?? const [])
        .whereType<Map>()
        .firstOrNull;
    return Card(
      clipBehavior: Clip.antiAlias,
      margin: EdgeInsets.zero,
      child: InkWell(
        onTap: onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Stack(
              children: [
                EventImage(
                  imageUrl: event['image_url']?.toString(),
                  eventId: event['id']?.toString(),
                  eventCategory: event['event_category']?.toString(),
                  label: 'Artwork for ${event['title']?.toString() ?? 'event'}',
                  width: double.infinity,
                  height: 142,
                ),
                if (sponsor != null)
                  Positioned(
                    top: 10,
                    left: showDateBadge && localStart != null ? null : 10,
                    right: showDateBadge && localStart != null ? 10 : null,
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 9, vertical: 5),
                      decoration: BoxDecoration(
                        color: AppColors.surface.withValues(alpha: .94),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text('Sponsored · ${sponsor['name'] ?? 'Partner'}',
                          style: Theme.of(context).textTheme.labelSmall),
                    ),
                  ),
                if (showDateBadge && localStart != null)
                  Positioned(
                    top: 10,
                    left: 10,
                    child: Container(
                      constraints: const BoxConstraints(minWidth: 48),
                      padding: const EdgeInsets.symmetric(
                          horizontal: 9, vertical: 6),
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: .96),
                        borderRadius: BorderRadius.circular(10),
                        boxShadow: const [
                          BoxShadow(
                              color: Colors.black26,
                              blurRadius: 8,
                              offset: Offset(0, 2)),
                        ],
                      ),
                      child: Column(mainAxisSize: MainAxisSize.min, children: [
                        Text(
                            DateFormat.MMM(
                                    Localizations.localeOf(context).toString())
                                .format(localStart),
                            style: Theme.of(context)
                                .textTheme
                                .labelSmall
                                ?.copyWith(fontWeight: FontWeight.w700)),
                        Text(localStart.day.toString(),
                            style: Theme.of(context)
                                .textTheme
                                .titleMedium
                                ?.copyWith(fontWeight: FontWeight.w800)),
                      ]),
                    ),
                  ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(event['title']?.toString() ?? 'Event',
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 3),
                  if (start != null)
                    _CatalogueMeta(
                        icon: Icons.calendar_today_outlined,
                        text: _formatDate(start)),
                  if (venue.isNotEmpty || city.isNotEmpty)
                    _CatalogueMeta(
                        icon: Icons.place_outlined,
                        text: venue.isEmpty || city.isEmpty
                            ? (city.isEmpty ? venue : city)
                            : '$venue · $city'),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              _eventPrice(event),
                              style: Theme.of(context)
                                  .textTheme
                                  .titleMedium
                                  ?.copyWith(color: AppColors.primary),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            if (opensLater)
                              Text('Booking opens ${_formatDate(salesStart)}',
                                  style: Theme.of(context).textTheme.bodySmall),
                          ],
                        ),
                      ),
                      const Icon(Icons.arrow_forward_rounded,
                          color: AppColors.primary),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _EventRow extends StatelessWidget {
  const _EventRow({required this.event, required this.onTap});

  final Map<String, dynamic> event;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final start = DateTime.tryParse(event['starts_at']?.toString() ?? '');
    final city = event['venue_city']?.toString().trim() ?? '';
    final venue = event['venue_name']?.toString() ?? '';
    final location = city.isNotEmpty ? city : venue;
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              EventImage(
                imageUrl: event['image_url']?.toString(),
                eventId: event['id']?.toString(),
                eventCategory: event['event_category']?.toString(),
                label: 'Image for ${event['title']?.toString() ?? 'event'}',
                width: 76,
                height: 76,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(event['title']?.toString() ?? 'Event',
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.titleMedium),
                    if (location.isNotEmpty)
                      _CatalogueMeta(
                          icon: Icons.place_outlined, text: location),
                    if (start != null)
                      _CatalogueMeta(
                          icon: Icons.calendar_today_outlined,
                          text: _formatDate(start)),
                  ],
                ),
              ),
              const SizedBox(width: 6),
              SizedBox(
                width: 82,
                child: Text(_eventPrice(event),
                    textAlign: TextAlign.end,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.labelMedium?.copyWith(
                          color: event['availability_status'] == 'sold_out'
                              ? AppColors.error
                              : AppColors.primary,
                          fontWeight: FontWeight.w700,
                        )),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

String _eventPrice(Map<String, dynamic> event) {
  if (event['availability_status'] == 'sold_out') return 'Sold out';
  if (event['booking_status'] == 'closed') return 'Booking closed';
  final allCategories = (event['categories'] as List<dynamic>? ?? const [])
      .whereType<Map>()
      .toList();
  if (allCategories.isEmpty) return 'Unavailable';
  final availableCategories = allCategories
      .where((category) =>
          category['available_quantity'] is! num ||
          (category['available_quantity'] as num) > 0)
      .toList();
  if (availableCategories.isEmpty) return 'Sold out';
  final categories = availableCategories
      .where((category) =>
          category['allocation_type'] == 'paid' &&
          (category['price_minor'] as num? ?? 0) > 0)
      .toList();
  if (categories.isEmpty) {
    if (availableCategories
        .any((category) => category['allocation_type'] == 'free')) {
      return 'Free';
    }
    return availableCategories
            .any((category) => category['allocation_type'] == 'complimentary')
        ? 'Invitation required'
        : 'Unavailable';
  }
  categories.sort(
      (a, b) => (a['price_minor'] as num).compareTo(b['price_minor'] as num));
  final minor = (categories.first['price_minor'] as num).toInt();
  final code = event['currency_code']?.toString() ?? 'BWP';
  final precision = (event['currency_precision'] as num?)?.toInt() ?? 2;
  final price = 'From ${_formatMoney(minor, code, precision)}';
  return availableCategories
          .any((category) => category['allocation_type'] == 'free')
      ? 'Free · $price'
      : price;
}

String _eventCategoryLabel(String category) => switch (category.toLowerCase()) {
      'community' => 'Community',
      'music' => 'Music',
      'sports' => 'Sports',
      'club_game' => 'Club game',
      'business' => 'Business',
      'arts' => 'Arts & culture',
      'family' => 'Family',
      _ => 'Other',
    };

String _titleCase(String value) => value
    .split(RegExp(r'[_\s]+'))
    .where((part) => part.isNotEmpty)
    .map((part) => '${part[0].toUpperCase()}${part.substring(1)}')
    .join(' ');

class _CategoryLabelChip extends StatelessWidget {
  const _CategoryLabelChip({required this.category, required this.label});

  final String category;
  final String label;

  @override
  Widget build(BuildContext context) {
    final style =
        EventCategoryStyle.resolve(category, Theme.of(context).brightness);
    return Container(
      constraints: const BoxConstraints(maxWidth: 180),
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
      decoration: BoxDecoration(
        color: style.background,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: style.foreground.withValues(alpha: .28)),
      ),
      child: Text(label,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context)
              .textTheme
              .labelSmall
              ?.copyWith(color: style.foreground, fontWeight: FontWeight.w700)),
    );
  }
}

class _EventPricePill extends StatelessWidget {
  const _EventPricePill({required this.label, required this.soldOut});

  final String label;
  final bool soldOut;

  @override
  Widget build(BuildContext context) {
    final color = soldOut ? AppColors.warning : AppColors.primary;
    final background = soldOut ? AppColors.warningBg : AppColors.infoBg;
    return ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 150),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
        decoration: BoxDecoration(
            color: background, borderRadius: BorderRadius.circular(18)),
        child: Text(label,
            maxLines: 2,
            textAlign: TextAlign.right,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context)
                .textTheme
                .labelMedium
                ?.copyWith(color: color, fontWeight: FontWeight.w700)),
      ),
    );
  }
}

class _CatalogueMeta extends StatelessWidget {
  const _CatalogueMeta({required this.icon, required this.text});
  final IconData icon;
  final String text;
  @override
  Widget build(BuildContext context) => Padding(
      padding: const EdgeInsets.only(top: 7),
      child: Row(children: [
        Icon(icon, size: 16, color: AppColors.textSecondary),
        const SizedBox(width: 7),
        Expanded(
            child: Text(text,
                style: Theme.of(context).textTheme.bodySmall,
                maxLines: 1,
                overflow: TextOverflow.ellipsis))
      ]));
}

class _CustomerEventDetailsScreen extends StatefulWidget {
  const _CustomerEventDetailsScreen({required this.event, required this.dio});

  final Map<String, dynamic> event;
  final Dio dio;

  @override
  State<_CustomerEventDetailsScreen> createState() =>
      _CustomerEventDetailsScreenState();
}

class _CustomerEventDetailsScreenState
    extends State<_CustomerEventDetailsScreen> {
  String? _busyCategoryId;
  final _ticketSectionKey = GlobalKey();
  bool _ticketLookupComplete = false;
  bool _hasValidTicket = false;
  int _galleryPage = 0;
  final Map<String, int> _selectedPaidTickets = {};
  EventCheckoutSession? _checkoutSession;

  @override
  void initState() {
    super.initState();
    _checkExistingTicket();
  }

  Future<void> _checkExistingTicket() async {
    try {
      final response = await widget.dio.get<Object?>('/v1/events/tickets');
      final body = response.data as Map<String, dynamic>;
      final rows = (body['data'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>();
      final eventId = widget.event['id']?.toString();
      final ownsTicket = rows.any((ticket) =>
          ticket['event_id']?.toString() == eventId &&
          ticket['status'] != 'cancelled' &&
          ticket['status'] != 'revoked');
      if (mounted) {
        setState(() {
          _hasValidTicket = ownsTicket;
          _ticketLookupComplete = true;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _ticketLookupComplete = true);
    }
  }

  Future<void> _register(Map<String, dynamic> category) async {
    final eventId = widget.event['id'];
    final categoryId = category['id'];
    setState(() => _busyCategoryId = categoryId.toString());
    try {
      await widget.dio.post<Object?>(
        '/v1/events/$eventId/free-registrations',
        data: {'category_id': categoryId},
        options: Options(headers: {'Idempotency-Key': const Uuid().v4()}),
      );
      if (mounted) {
        context.go('/events/registration-confirmation', extra: {
          'event_title': widget.event['title']?.toString() ?? '',
          'category': category['name']?.toString() ?? '',
        });
      }
    } on DioException catch (error) {
      final message = error.response?.data is Map
          ? (error.response!.data as Map)['message']?.toString()
          : null;
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(message ?? 'Registration failed.')),
        );
      }
    } finally {
      if (mounted) setState(() => _busyCategoryId = null);
    }
  }

  void _openInvitation(
      Map<String, dynamic> event, Map<String, dynamic> category) {
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => FreeEventRegistrationScreen(
        eventId: event['id'].toString(),
        categoryId: category['id'].toString(),
        eventTitle: event['title']?.toString(),
        categoryName: category['name']?.toString(),
      ),
    ));
  }

  bool _hasContent(Map<String, dynamic> event, String field) =>
      event[field]?.toString().trim().isNotEmpty ?? false;

  List<String?> _galleryImages(Map<String, dynamic> event) {
    final images = <String?>[];
    final cover = event['image_url']?.toString().trim();
    if (cover != null && cover.isNotEmpty) images.add(cover);
    final gallery = event['gallery_image_urls'];
    if (gallery is List) {
      for (final value in gallery.whereType<String>()) {
        final url = value.trim();
        if (url.isNotEmpty && !images.contains(url)) images.add(url);
      }
    }
    return images.isEmpty ? [null] : images;
  }

  int _maxTicketQuantity(
      Map<String, dynamic> event, Map<String, dynamic> category) {
    final perPerson = (category['max_per_person'] as num?)?.toInt() ?? 10;
    final orderLimit = (event['max_tickets_per_order'] as num?)?.toInt() ?? 10;
    final available = (category['available_quantity'] as num?)?.toInt() ?? 50;
    return [perPerson, orderLimit, available, 50]
        .reduce((left, right) => left < right ? left : right)
        .clamp(0, 50)
        .toInt();
  }

  int _maxSelectableQuantity(
      Map<String, dynamic> event, Map<String, dynamic> category) {
    final orderLimit = (event['max_tickets_per_order'] as num?)?.toInt() ?? 10;
    final otherSelected = _selectedPaidTickets.entries
        .where((entry) => entry.key != category['id'].toString())
        .fold<int>(0, (total, entry) => total + entry.value);
    return _maxTicketQuantity(event, category)
        .clamp(0, (orderLimit - otherSelected).clamp(0, orderLimit))
        .toInt();
  }

  void _setPaidQuantity(
      Map<String, dynamic> event, Map<String, dynamic> category, int value) {
    final id = category['id'].toString();
    final next = value.clamp(0, _maxSelectableQuantity(event, category));
    setState(() {
      if (next == 0) {
        _selectedPaidTickets.remove(id);
      } else {
        _selectedPaidTickets[id] = next;
      }
      if (_checkoutSession != null &&
          !mapEquals(
              _checkoutSession!.selectedQuantities, _selectedPaidTickets)) {
        _checkoutSession = null;
      }
    });
  }

  Future<void> _primaryAction(
    Map<String, dynamic> event,
    List<Map<String, dynamic>> categories,
    bool bookingOpensLater,
    bool bookingClosed,
    bool soldOut,
  ) async {
    if (_hasValidTicket) {
      context.go('/events/tickets');
      return;
    }
    if (!_ticketLookupComplete ||
        bookingOpensLater ||
        bookingClosed ||
        soldOut ||
        categories.isEmpty) return;
    final paidSelection = Map<String, int>.from(_selectedPaidTickets);
    if (paidSelection.isNotEmpty) {
      final session = _checkoutSession ??=
          EventCheckoutSession(selectedQuantities: paidSelection);
      await EventCheckoutScreen.showPaymentSheet(
        context: context,
        event: event,
        selectedQuantities: paidSelection,
        dio: widget.dio,
        session: session,
      );
      return;
    }
    if (categories.length == 1) {
      final category = categories.single;
      if (category['allocation_type'] == 'complimentary') {
        _openInvitation(event, category);
        return;
      }
      if (category['allocation_type'] == 'free') {
        _register(category);
        return;
      }
    }
    final section = _ticketSectionKey.currentContext;
    if (section != null) {
      Scrollable.ensureVisible(section,
          duration: const Duration(milliseconds: 280),
          curve: Curves.easeOut,
          alignment: .08);
    }
    if (categories.any((category) => category['allocation_type'] == 'paid')) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Select at least one available ticket before booking.'),
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    final event = widget.event;
    final startsAt = DateTime.tryParse(event['starts_at']?.toString() ?? '');
    final salesStart =
        DateTime.tryParse(event['sales_start_at']?.toString() ?? '');
    final salesEnd = DateTime.tryParse(event['sales_end_at']?.toString() ?? '');
    final bookingOpensLater =
        salesStart != null && salesStart.isAfter(DateTime.now());
    final bookingClosed = event['booking_status'] == 'closed' ||
        (salesEnd != null && !salesEnd.isAfter(DateTime.now()));
    final categories = (event['categories'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    final hasPaid = categories.any((category) =>
        category['allocation_type'] == 'paid' &&
        (category['price_minor'] as num? ?? 0) > 0);
    final hasInvitation = categories
        .any((category) => category['allocation_type'] == 'complimentary');
    final soldOut = event['availability_status'] == 'sold_out';
    final galleryImages = _galleryImages(event);
    final textScale = MediaQuery.textScalerOf(context).scale(16) / 16;
    final heroHeight = (390 + ((textScale - 1) * 144).clamp(0, 120)).toDouble();
    final actionLabel = _hasValidTicket
        ? 'View my ticket'
        : soldOut
            ? 'Sold out'
            : bookingOpensLater
                ? 'Booking opens ${_formatDate(salesStart)}'
                : bookingClosed
                    ? 'Registration closed'
                    : categories.isEmpty
                        ? 'Registration unavailable'
                        : hasPaid
                            ? 'Book tickets'
                            : hasInvitation
                                ? 'Accept invitation'
                                : 'Register for free';

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
          toolbarHeight: 0,
          backgroundColor: Colors.transparent,
          elevation: 0,
          systemOverlayStyle: SystemUiOverlayStyle.light),
      bottomNavigationBar: SafeArea(
        top: false,
        child: Container(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 10),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.surface,
            border: const Border(
                top: BorderSide(color: AppColors.outline, width: .7)),
          ),
          child: FilledButton(
            onPressed: !_ticketLookupComplete ||
                    bookingOpensLater ||
                    bookingClosed ||
                    soldOut ||
                    categories.isEmpty
                ? null
                : () => _primaryAction(event, categories, bookingOpensLater,
                    bookingClosed, soldOut),
            child:
                Text(!_ticketLookupComplete ? 'Checking ticket…' : actionLabel),
          ),
        ),
      ),
      body: ListView(
        padding: EdgeInsets.zero,
        children: [
          SizedBox(
            height: heroHeight,
            child: Stack(fit: StackFit.expand, children: [
              if (galleryImages.length == 1)
                EventImage(
                  imageUrl: galleryImages.first,
                  eventId: event['id']?.toString(),
                  eventCategory: event['event_category']?.toString(),
                  label: 'Image for ' + (event['title']?.toString() ?? 'event'),
                  width: double.infinity,
                  height: heroHeight,
                  iconSize: 72,
                  borderRadius: 0,
                )
              else
                PageView.builder(
                  itemCount: galleryImages.length,
                  onPageChanged: (page) => setState(() => _galleryPage = page),
                  itemBuilder: (context, index) => EventImage(
                    imageUrl: galleryImages[index],
                    eventId: event['id']?.toString(),
                    eventCategory: event['event_category']?.toString(),
                    label: 'Event image ' + (index + 1).toString(),
                    width: double.infinity,
                    height: heroHeight,
                    iconSize: 72,
                    borderRadius: 0,
                  ),
                ),
              const IgnorePointer(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [
                        Color(0x24000000),
                        Color(0x00000000),
                        Color(0xE610172A),
                      ],
                      stops: [0, .38, 1],
                    ),
                  ),
                ),
              ),
              Positioned(
                top: MediaQuery.paddingOf(context).top + 8,
                left: 16,
                child: Material(
                  color: Colors.black54,
                  shape: const CircleBorder(),
                  child: IconButton(
                    tooltip: 'Back to events',
                    onPressed: () => Navigator.of(context).maybePop(),
                    color: Colors.white,
                    icon: const Icon(Icons.arrow_back_rounded),
                  ),
                ),
              ),
              Positioned(
                left: 20,
                right: 20,
                bottom: galleryImages.length > 1 ? 32 : 20,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      _CategoryLabelChip(
                        category:
                            event['event_category']?.toString() ?? 'other',
                        label: _eventCategoryLabel(
                            event['event_category']?.toString() ?? 'other'),
                      ),
                      _EventPricePill(
                        label: soldOut
                            ? 'Sold out'
                            : bookingOpensLater
                                ? 'Booking opens ' + _formatDate(salesStart!)
                                : bookingClosed
                                    ? 'Booking closed'
                                    : _eventPrice(event),
                        soldOut: soldOut,
                      ),
                    ]),
                    const SizedBox(height: 10),
                    Text(
                      event['title']?.toString() ?? 'Event',
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                      style:
                          Theme.of(context).textTheme.headlineSmall?.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w800,
                        shadows: const [
                          Shadow(color: Colors.black54, blurRadius: 8),
                        ],
                      ),
                    ),
                    if (startsAt != null) ...[
                      const SizedBox(height: 8),
                      Row(children: [
                        const Icon(Icons.calendar_today_outlined,
                            size: 17, color: Colors.white),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            DateFormat.yMMMd(
                                    Localizations.localeOf(context).toString())
                                .add_jm()
                                .format(startsAt.toLocal()),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: Theme.of(context)
                                .textTheme
                                .bodyMedium
                                ?.copyWith(
                              color: Colors.white,
                              shadows: const [
                                Shadow(color: Colors.black54, blurRadius: 6),
                              ],
                            ),
                          ),
                        ),
                      ]),
                    ],
                    if (_hasContent(event, 'venue_name') ||
                        _hasContent(event, 'venue_city')) ...[
                      const SizedBox(height: 5),
                      Row(children: [
                        const Icon(Icons.place_outlined,
                            size: 18, color: Colors.white),
                        const SizedBox(width: 7),
                        Expanded(
                          child: Text(
                            [
                              event['venue_name']?.toString(),
                              event['venue_city']?.toString(),
                            ]
                                .whereType<String>()
                                .where((value) => value.isNotEmpty)
                                .join(' · '),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: Theme.of(context)
                                .textTheme
                                .bodyMedium
                                ?.copyWith(
                              color: Colors.white,
                              shadows: const [
                                Shadow(color: Colors.black54, blurRadius: 6),
                              ],
                            ),
                          ),
                        ),
                      ]),
                    ],
                    if (galleryImages.length > 1) ...[
                      const SizedBox(height: 10),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: List.generate(
                          galleryImages.length,
                          (index) => AnimatedContainer(
                            duration: const Duration(milliseconds: 160),
                            width: index == _galleryPage ? 18 : 7,
                            height: 7,
                            margin: const EdgeInsets.symmetric(horizontal: 3),
                            decoration: BoxDecoration(
                              color: index == _galleryPage
                                  ? Colors.white
                                  : Colors.white.withValues(alpha: .55),
                              borderRadius: BorderRadius.circular(8),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 22, 20, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('About event',
                    style: Theme.of(context).textTheme.titleLarge),
                const SizedBox(height: 8),
                Text(
                  _hasContent(event, 'description')
                      ? event['description'].toString().trim()
                      : 'Event details will be shared by the organiser.',
                  style: Theme.of(context).textTheme.bodyLarge,
                ),
                if (_hasContent(event, 'lineup')) ...[
                  const SizedBox(height: 18),
                  Text('Lineup',
                      style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 6),
                  ...event['lineup']
                      .toString()
                      .split(RegExp(r'\r?\n'))
                      .map((line) => line.trim())
                      .where((line) => line.isNotEmpty)
                      .map((line) => Padding(
                            padding: const EdgeInsets.symmetric(vertical: 3),
                            child: Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Icon(Icons.circle,
                                      size: 7, color: AppColors.primary),
                                  const SizedBox(width: 10),
                                  Expanded(child: Text(line)),
                                ]),
                          )),
                ],
                if (_hasContent(event, 'venue_address')) ...[
                  const SizedBox(height: 18),
                  _DetailLine(
                      label: 'Address',
                      value: event['venue_address'].toString()),
                ],
                if (_hasContent(event, 'entry_policy') ||
                    _hasContent(event, 'cancellation_policy') ||
                    _hasContent(event, 'transfer_policy')) ...[
                  const SizedBox(height: 14),
                  Text('Event information',
                      style: Theme.of(context).textTheme.titleMedium),
                  if (_hasContent(event, 'entry_policy'))
                    _DetailLine(
                        label: 'Entry',
                        value: event['entry_policy'].toString()),
                  if (_hasContent(event, 'cancellation_policy'))
                    _DetailLine(
                        label: 'Cancellation',
                        value: event['cancellation_policy'].toString()),
                  if (_hasContent(event, 'transfer_policy'))
                    _DetailLine(
                        label: 'Transfer',
                        value: event['transfer_policy'].toString()),
                ],
              ],
            ),
          ),
          const SizedBox(height: 24),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: KeyedSubtree(
              key: _ticketSectionKey,
              child: Text('Available tickets',
                  style: Theme.of(context).textTheme.titleLarge),
            ),
          ),
          const SizedBox(height: 12),
          ...categories.map((category) {
            final categoryId = category['id'].toString();
            final isBusy = _busyCategoryId == categoryId;
            final isPaid = category['allocation_type'] == 'paid' &&
                (category['price_minor'] as num? ?? 0) > 0;
            final isInvitationOnly =
                category['allocation_type'] == 'complimentary';
            final available = (category['available_quantity'] as num?)?.toInt();
            final soldOutCategory = available != null && available < 1;
            final selectedQuantity = _selectedPaidTickets[categoryId] ?? 0;
            final maximumQuantity = _maxSelectableQuantity(event, category);
            final categoryUnavailable = soldOutCategory ||
                bookingOpensLater ||
                bookingClosed ||
                soldOut;
            final categoryPrice = isPaid
                ? _formatMoney(
                    (category['price_minor'] as num).toInt(),
                    event['currency_code']?.toString() ?? 'BWP',
                    (event['currency_precision'] as num?)?.toInt() ?? 2,
                  )
                : 'Free';
            return Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: Card(
                margin: const EdgeInsets.only(bottom: 12),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Expanded(
                              child: Text(
                                  category['name']?.toString() ?? 'Ticket',
                                  style:
                                      Theme.of(context).textTheme.titleMedium),
                            ),
                            const SizedBox(width: 8),
                            Text(categoryPrice,
                                style: Theme.of(context)
                                    .textTheme
                                    .titleSmall
                                    ?.copyWith(color: AppColors.primary)),
                          ]),
                      const SizedBox(height: 5),
                      Text(
                        soldOutCategory
                            ? 'Sold out'
                            : bookingOpensLater
                                ? 'Booking opens ' + _formatDate(salesStart!)
                                : bookingClosed
                                    ? 'Registration closed'
                                    : isInvitationOnly
                                        ? 'Invitation required'
                                        : available == null
                                            ? 'Available'
                                            : available.toString() +
                                                ' available',
                        style: Theme.of(context)
                            .textTheme
                            .bodySmall
                            ?.copyWith(color: AppColors.textSecondary),
                      ),
                      if (isPaid) ...[
                        const SizedBox(height: 10),
                        if (!categoryUnavailable && maximumQuantity < 1)
                          Text('Order limit reached for this ticket type.',
                              style: Theme.of(context)
                                  .textTheme
                                  .bodySmall
                                  ?.copyWith(color: AppColors.textSecondary))
                        else if (!categoryUnavailable && maximumQuantity > 1)
                          Row(children: [
                            IconButton(
                              tooltip:
                                  'Remove one ' + category['name'].toString(),
                              onPressed: selectedQuantity < 1 ||
                                      _busyCategoryId != null
                                  ? null
                                  : () => _setPaidQuantity(
                                      event, category, selectedQuantity - 1),
                              icon: const Icon(Icons.remove_circle_outline),
                            ),
                            Text(selectedQuantity.toString(),
                                style: Theme.of(context).textTheme.titleMedium),
                            IconButton(
                              tooltip: 'Add one ' + category['name'].toString(),
                              onPressed: selectedQuantity >= maximumQuantity ||
                                      _busyCategoryId != null
                                  ? null
                                  : () => _setPaidQuantity(
                                      event, category, selectedQuantity + 1),
                              icon: const Icon(Icons.add_circle_outline),
                            ),
                            const Spacer(),
                            Text(_formatMoney(
                                (category['price_minor'] as num).toInt() *
                                    selectedQuantity,
                                event['currency_code']?.toString() ?? 'BWP',
                                (event['currency_precision'] as num?)
                                        ?.toInt() ??
                                    2)),
                          ])
                        else if (!categoryUnavailable)
                          CheckboxListTile(
                            contentPadding: EdgeInsets.zero,
                            value: selectedQuantity > 0,
                            onChanged: _busyCategoryId != null
                                ? null
                                : (checked) => _setPaidQuantity(
                                    event, category, checked == true ? 1 : 0),
                            title: Text(selectedQuantity == 0
                                ? 'Select ticket'
                                : 'Selected'),
                            controlAffinity: ListTileControlAffinity.leading,
                          ),
                      ] else if (categories.length > 1) ...[
                        const SizedBox(height: 12),
                        SizedBox(
                          width: double.infinity,
                          child: FilledButton(
                            onPressed:
                                categoryUnavailable || _busyCategoryId != null
                                    ? null
                                    : isInvitationOnly
                                        ? () => _openInvitation(event, category)
                                        : () => _register(category),
                            child: Text(soldOutCategory
                                ? 'Sold out'
                                : bookingOpensLater
                                    ? 'Booking opens ' +
                                        _formatDate(salesStart!)
                                    : bookingClosed
                                        ? 'Registration closed'
                                        : isBusy
                                            ? 'Registering...'
                                            : isInvitationOnly
                                                ? 'Accept invitation'
                                                : 'Register for free'),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            );
          }),
          const SizedBox(height: 28),
        ],
      ),
    );
  }
}

class _DetailLine extends StatelessWidget {
  const _DetailLine({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(children: [
          SizedBox(width: 72, child: Text(label)),
          Expanded(child: Text(value)),
        ]),
      );
}

String _formatDate(DateTime value) {
  final local = value.toLocal();
  final month = local.month.toString().padLeft(2, '0');
  final day = local.day.toString().padLeft(2, '0');
  final hour = local.hour.toString().padLeft(2, '0');
  final minute = local.minute.toString().padLeft(2, '0');
  return '${local.year}-$month-$day  $hour:$minute';
}

String _formatMoney(int minor, String currencyCode, int precision) {
  var divisor = 1;
  for (var index = 0; index < precision; index++) {
    divisor *= 10;
  }
  return '$currencyCode ${(minor / divisor).toStringAsFixed(precision)}';
}
