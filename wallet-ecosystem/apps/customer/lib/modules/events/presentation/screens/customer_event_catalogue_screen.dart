import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:uuid/uuid.dart';

import '../../../../core/di/providers.dart';
import '../widgets/event_image.dart';
import 'customer_event_checkout_screen.dart';

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
          .read(apiClientProvider)
          .dio
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
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Events')),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? Center(
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                    Text(_error!),
                    TextButton(onPressed: _load, child: const Text('Retry')),
                  ]))
                : _events.isEmpty
                    ? const Center(
                        child: Text(
                            'No upcoming events are open for registration.'))
                    : RefreshIndicator(
                        onRefresh: _load,
                        child: ListView.separated(
                          padding: const EdgeInsets.symmetric(vertical: 8),
                          itemCount: _events.length,
                          separatorBuilder: (_, __) => const Divider(height: 1),
                          itemBuilder: (context, index) {
                            final event = _events[index];
                            return _EventRow(
                              event: event,
                              onTap: () => Navigator.of(context).push(
                                MaterialPageRoute<void>(
                                  builder: (_) => _CustomerEventDetailsScreen(
                                    event: event,
                                    dio: ref.read(apiClientProvider).dio,
                                  ),
                                ),
                              ),
                            );
                          },
                        ),
                      ),
      );
}

class _EventRow extends StatelessWidget {
  const _EventRow({required this.event, required this.onTap});

  final Map<String, dynamic> event;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final start = DateTime.tryParse(event['starts_at']?.toString() ?? '');
    return ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
      leading: EventImage(
        imageUrl: event['image_url']?.toString(),
        eventId: event['id']?.toString(),
        label: 'Image for ${event['title']?.toString() ?? 'event'}',
        width: 72,
        height: 72,
      ),
      title: Text(event['title']?.toString() ?? 'Event'),
      subtitle: Padding(
        padding: const EdgeInsets.only(top: 4),
        child: Text([
          if ((event['organiser_name']?.toString() ?? '').isNotEmpty)
            event['organiser_name'].toString(),
          if (start != null) _formatDate(start),
        ].join('  •  ')),
      ),
      trailing: const Icon(Icons.chevron_right),
      onTap: onTap,
    );
  }
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
      if (mounted) context.go('/events/tickets');
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
    final categories = (event['categories'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();

    return Scaffold(
      appBar: AppBar(title: const Text('Event details')),
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
          const SizedBox(height: 16),
          Text(event['title']?.toString() ?? 'Event',
              style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 8),
          Text(event['organiser_name']?.toString() ?? ''),
          if (startsAt != null) ...[
            const SizedBox(height: 20),
            _DetailLine(label: 'Starts', value: _formatDate(startsAt)),
          ],
          if (endsAt != null)
            _DetailLine(label: 'Ends', value: _formatDate(endsAt)),
          if ((event['event_format']?.toString() ?? '').isNotEmpty)
            _DetailLine(
                label: 'Format', value: event['event_format'].toString()),
          const SizedBox(height: 24),
          Text('Tickets', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          ...categories.map((category) {
            final categoryId = category['id'].toString();
            final isBusy = _busyCategoryId == categoryId;
            final isPaid = category['allocation_type'] == 'paid' &&
                (category['price_minor'] as num? ?? 0) > 0;
            return Card(
              child: ListTile(
                title: Text(category['name']?.toString() ?? 'Ticket'),
                subtitle: Text(isPaid
                    ? '${_formatMoney((category['price_minor'] as num).toInt(), event['currency_code']?.toString() ?? 'BWP', (event['currency_precision'] as num?)?.toInt() ?? 2)} per ticket'
                    : 'Free  •  Up to ${category['max_per_person'] ?? 1} per customer'),
                trailing: FilledButton(
                  onPressed: _busyCategoryId == null
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
                          : () => _register(category)
                      : null,
                  child: Text(isBusy
                      ? 'Registering…'
                      : isPaid
                          ? 'Buy ticket'
                          : 'Register free'),
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
