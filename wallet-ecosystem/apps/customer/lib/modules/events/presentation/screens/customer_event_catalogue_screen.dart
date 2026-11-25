import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:uuid/uuid.dart';

import '../../../../core/di/providers.dart';
import '../../../../core/theme/design_tokens.dart';
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
    final visibleEvents = _events.where(_matches).toList();
    final featured =
        visibleEvents.isEmpty ? null : _featuredEvent(visibleEvents);
    final listEvents = featured == null
        ? visibleEvents
        : visibleEvents
            .where((event) => event['id'] != featured['id'])
            .toList();

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
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    Text(_error!, textAlign: TextAlign.center),
                    TextButton(onPressed: _load, child: const Text('Retry')),
                  ]),
                )
              : Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
                      child: TextField(
                        decoration: InputDecoration(
                          hintText: 'Search events, venues, or organisers',
                          prefixIcon: const Icon(Icons.search),
                          suffixIcon: _search.isEmpty
                              ? null
                              : IconButton(
                                  tooltip: 'Clear search',
                                  onPressed: () => setState(() => _search = ''),
                                  icon: const Icon(Icons.close),
                                ),
                        ),
                        onChanged: (value) => setState(() => _search = value),
                      ),
                    ),
                    SizedBox(
                      height: 54,
                      child: ListView(
                        scrollDirection: Axis.horizontal,
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        children: [
                          for (final label in const [
                            'All',
                            'This Weekend',
                            'Free',
                            'Paid',
                          ])
                            Padding(
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 4),
                              child: FilterChip(
                                label: Text(label),
                                selected: _filter == label,
                                onSelected: (_) =>
                                    setState(() => _filter = label),
                              ),
                            ),
                          if (locations.length > 1)
                            Padding(
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 4),
                              child: PopupMenuButton<String?>(
                                onSelected: (value) =>
                                    setState(() => _location = value),
                                itemBuilder: (_) => [
                                  const PopupMenuItem<String?>(
                                    value: null,
                                    child: Text('All locations'),
                                  ),
                                  ...locations
                                      .map((value) => PopupMenuItem<String?>(
                                            value: value,
                                            child: Text(value),
                                          )),
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
                        child: visibleEvents.isEmpty
                            ? ListView(
                                physics: const AlwaysScrollableScrollPhysics(),
                                children: const [
                                  SizedBox(height: 120),
                                  Icon(Icons.event_busy_outlined,
                                      size: 42, color: AppColors.textSecondary),
                                  SizedBox(height: 12),
                                  Center(
                                      child: Text(
                                          'No events match these filters.')),
                                ],
                              )
                            : ListView(
                                physics: const AlwaysScrollableScrollPhysics(),
                                padding:
                                    const EdgeInsets.fromLTRB(16, 8, 16, 24),
                                children: [
                                  if (featured != null) ...[
                                    Row(
                                      children: [
                                        Expanded(
                                          child: Text('Featured event',
                                              style: Theme.of(context)
                                                  .textTheme
                                                  .titleLarge),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 10),
                                    _FeaturedEventCard(
                                      event: featured,
                                      onTap: () => _openEvent(featured),
                                    ),
                                  ],
                                  if (listEvents.isNotEmpty) ...[
                                    Padding(
                                      padding: const EdgeInsets.fromLTRB(
                                          2, 24, 2, 10),
                                      child: Text('Upcoming events',
                                          style: Theme.of(context)
                                              .textTheme
                                              .titleLarge),
                                    ),
                                    ...listEvents.map((event) => _EventRow(
                                          event: event,
                                          onTap: () => _openEvent(event),
                                        )),
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
    ].whereType<Object>().join(' ').toLowerCase();
    if (query.isNotEmpty && !searchable.contains(query)) return false;
    if (_location != null && _eventLocation(event) != _location) {
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

  bool _isPaidCategory(Map category) =>
      category['allocation_type'] == 'paid' &&
      (category['price_minor'] as num? ?? 0) > 0;

  String _eventLocation(Map<String, dynamic> event) {
    final city = event['venue_city']?.toString().trim() ?? '';
    return city.isNotEmpty
        ? city
        : (event['venue_name']?.toString().trim() ?? '');
  }

  Map<String, dynamic>? _featuredEvent(List<Map<String, dynamic>> events) {
    for (final event in events) {
      if (event['sponsors'] is List && (event['sponsors'] as List).isNotEmpty) {
        return event;
      }
    }
    return null;
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

class _FeaturedEventCard extends StatelessWidget {
  const _FeaturedEventCard({required this.event, required this.onTap});
  final Map<String, dynamic> event;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final start = DateTime.tryParse(event['starts_at']?.toString() ?? '');
    final venue = event['venue_name']?.toString() ?? '';
    final city = event['venue_city']?.toString().trim() ?? '';
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
                  label: 'Featured event artwork',
                  width: double.infinity,
                  height: 196,
                ),
                if (sponsor != null)
                  Positioned(
                    top: 12,
                    left: 12,
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 6),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(.94),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text('Sponsored · ${sponsor['name'] ?? 'Partner'}',
                          style: Theme.of(context).textTheme.labelSmall),
                    ),
                  ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(event['title']?.toString() ?? 'Event',
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 8),
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
    final salesStart =
        DateTime.tryParse(event['sales_start_at']?.toString() ?? '');
    final opensLater = salesStart != null && salesStart.isAfter(DateTime.now());
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
                label: 'Image for ${event['title']?.toString() ?? 'event'}',
                width: 96,
                height: 100,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(event['title']?.toString() ?? 'Event',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.titleMedium),
                    if (start != null)
                      _CatalogueMeta(
                          icon: Icons.calendar_today_outlined,
                          text: _formatDate(start)),
                    if (location.isNotEmpty)
                      _CatalogueMeta(
                          icon: Icons.place_outlined, text: location),
                    const SizedBox(height: 6),
                    Text(_eventPrice(event),
                        style: Theme.of(context).textTheme.labelLarge?.copyWith(
                              color: AppColors.primary,
                              fontWeight: FontWeight.w700,
                            ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis),
                    if (opensLater)
                      Text('Booking opens ${_formatDate(salesStart)}',
                          style:
                              Theme.of(context).textTheme.bodySmall?.copyWith(
                                    color: AppColors.textSecondary,
                                  ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis),
                  ],
                ),
              ),
              const SizedBox(width: 4),
              const Icon(Icons.chevron_right_rounded,
                  color: AppColors.textSecondary),
            ],
          ),
        ),
      ),
    );
  }
}

String _eventPrice(Map<String, dynamic> event) {
  final categories = (event['categories'] as List<dynamic>? ?? const [])
      .whereType<Map>()
      .where((category) =>
          category['allocation_type'] == 'paid' &&
          (category['price_minor'] as num? ?? 0) > 0)
      .toList();
  if (categories.isEmpty) return 'Free';
  categories.sort(
      (a, b) => (a['price_minor'] as num).compareTo(b['price_minor'] as num));
  final minor = (categories.first['price_minor'] as num).toInt();
  final code = event['currency_code']?.toString() ?? 'BWP';
  final precision = (event['currency_precision'] as num?)?.toInt() ?? 2;
  return 'From ${_formatMoney(minor, code, precision)}';
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
      if (mounted)
        context.go('/events/registration-confirmation', extra: {
          'event_title': widget.event['title']?.toString() ?? '',
          'category': category['name']?.toString() ?? '',
        });
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

  @override
  Widget build(BuildContext context) {
    final event = widget.event;
    final startsAt = DateTime.tryParse(event['starts_at']?.toString() ?? '');
    final endsAt = DateTime.tryParse(event['ends_at']?.toString() ?? '');
    final salesStart =
        DateTime.tryParse(event['sales_start_at']?.toString() ?? '');
    final bookingOpensLater =
        salesStart != null && salesStart.isAfter(DateTime.now());
    final categories = (event['categories'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    final hasPaid = categories.any((category) =>
        category['allocation_type'] == 'paid' &&
        (category['price_minor'] as num? ?? 0) > 0);
    final hasInvitation = categories
        .any((category) => category['allocation_type'] == 'complimentary');
    final actionLabel = _hasValidTicket
        ? 'View my ticket'
        : bookingOpensLater
            ? 'Booking opens ${_formatDate(salesStart)}'
            : hasPaid
                ? 'Select tickets'
                : hasInvitation
                    ? 'Accept invitation'
                    : 'Register for free';

    return Scaffold(
      appBar: AppBar(title: const Text('Event details')),
      bottomNavigationBar: SafeArea(
        top: false,
        child: Container(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 14),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.surface,
            border: const Border(
                top: BorderSide(color: AppColors.outline, width: .7)),
          ),
          child: FilledButton(
            onPressed: !_ticketLookupComplete || bookingOpensLater
                ? null
                : _hasValidTicket
                    ? () => context.go('/events/tickets')
                    : categories.isEmpty
                        ? null
                        : () {
                            final section = _ticketSectionKey.currentContext;
                            if (section != null) {
                              Scrollable.ensureVisible(
                                section,
                                duration: const Duration(milliseconds: 250),
                                curve: Curves.easeOut,
                                alignment: .1,
                              );
                            }
                          },
            child: Text(actionLabel),
          ),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          EventImage(
            imageUrl: event['image_url']?.toString(),
            eventId: event['id']?.toString(),
            label: 'Image for ${event['title']?.toString() ?? 'event'}',
            width: double.infinity,
            height: 220,
            iconSize: 64,
          ),
          const SizedBox(height: 20),
          Text(event['title']?.toString() ?? 'Event',
              style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Theme.of(context).colorScheme.surface,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppColors.outline),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (startsAt != null)
                  _CatalogueMeta(
                      icon: Icons.calendar_today_outlined,
                      text: _formatDate(startsAt)),
                if ((event['venue_name']?.toString() ?? '').isNotEmpty ||
                    (event['venue_city']?.toString() ?? '').isNotEmpty)
                  _CatalogueMeta(
                      icon: Icons.place_outlined,
                      text: [
                        event['venue_name']?.toString(),
                        event['venue_city']?.toString(),
                      ]
                          .whereType<String>()
                          .where((value) => value.isNotEmpty)
                          .join(' · ')),
                if ((event['organiser_name']?.toString() ?? '').isNotEmpty)
                  _CatalogueMeta(
                      icon: Icons.business_outlined,
                      text: event['organiser_name'].toString()),
                const SizedBox(height: 8),
                Row(children: [
                  const Icon(Icons.check_circle_outline,
                      size: 16, color: AppColors.success),
                  const SizedBox(width: 7),
                  Text(
                      bookingOpensLater
                          ? 'Booking opens ${_formatDate(salesStart)}'
                          : 'Booking open',
                      style: Theme.of(context).textTheme.labelLarge?.copyWith(
                          color: bookingOpensLater
                              ? AppColors.textSecondary
                              : AppColors.success)),
                ]),
              ],
            ),
          ),
          if (event['sponsors'] is List &&
              (event['sponsors'] as List).isNotEmpty) ...[
            const SizedBox(height: 18),
            Text('Sponsors', style: Theme.of(context).textTheme.titleMedium),
            ...(event['sponsors'] as List)
                .whereType<Map<String, dynamic>>()
                .map((sponsor) => ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: sponsor['logo_url'] is String &&
                              (sponsor['logo_url'] as String).isNotEmpty
                          ? Image.network(sponsor['logo_url'] as String,
                              width: 40,
                              height: 40,
                              errorBuilder: (_, __, ___) =>
                                  const Icon(Icons.business))
                          : const Icon(Icons.business),
                      title: Text(sponsor['name']?.toString() ?? 'Sponsor'),
                      subtitle: Text(sponsor['description']?.toString() ?? ''),
                    )),
          ],
          if ((event['venue_address']?.toString() ?? '').isNotEmpty)
            _DetailLine(
                label: 'Address', value: event['venue_address'].toString()),
          if (endsAt != null ||
              (event['event_format']?.toString() ?? '').isNotEmpty) ...[
            const SizedBox(height: 10),
            if (endsAt != null)
              _DetailLine(label: 'Ends', value: _formatDate(endsAt)),
            if ((event['event_format']?.toString() ?? '').isNotEmpty)
              _DetailLine(
                  label: 'Format', value: event['event_format'].toString()),
          ],
          const SizedBox(height: 24),
          KeyedSubtree(
            key: _ticketSectionKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Ticket options',
                    style: Theme.of(context).textTheme.titleLarge),
                const SizedBox(height: 4),
                Text('Choose an option to continue.',
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: AppColors.textSecondary,
                        )),
              ],
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
            return Card(
              margin: const EdgeInsets.only(bottom: 12),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(category['name']?.toString() ?? 'Ticket',
                        style: Theme.of(context).textTheme.titleMedium),
                    const SizedBox(height: 5),
                    Text(
                      isPaid
                          ? '${_formatMoney((category['price_minor'] as num).toInt(), event['currency_code']?.toString() ?? 'BWP', (event['currency_precision'] as num?)?.toInt() ?? 2)} per ticket'
                          : isInvitationOnly
                              ? 'Invitation required'
                              : 'Free registration · Up to ${category['max_per_person'] ?? 1} per customer',
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: AppColors.textSecondary,
                          ),
                    ),
                    const SizedBox(height: 14),
                    SizedBox(
                      width: double.infinity,
                      child: FilledButton(
                        onPressed: _busyCategoryId == null && !bookingOpensLater
                            ? isPaid
                                ? () => Navigator.of(context).push(
                                      MaterialPageRoute<void>(
                                        builder: (_) => EventCheckoutScreen(
                                          event: event,
                                          category: category,
                                          dio: widget.dio,
                                        ),
                                      ),
                                    )
                                : isInvitationOnly
                                    ? () => Navigator.of(context).push(
                                          MaterialPageRoute<void>(
                                            builder: (_) =>
                                                FreeEventRegistrationScreen(
                                              eventId: event['id'].toString(),
                                              categoryId:
                                                  category['id'].toString(),
                                              eventTitle:
                                                  event['title']?.toString(),
                                              categoryName:
                                                  category['name']?.toString(),
                                            ),
                                          ),
                                        )
                                    : () => _register(category)
                            : null,
                        child: Text(bookingOpensLater
                            ? 'Booking opens ${_formatDate(salesStart)}'
                            : isBusy
                                ? 'Registering…'
                                : isPaid
                                    ? 'Select ${category['name'] ?? 'tickets'}'
                                    : isInvitationOnly
                                        ? 'Redeem invitation'
                                        : 'Register free'),
                      ),
                    ),
                  ],
                ),
              ),
            );
          }),
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
