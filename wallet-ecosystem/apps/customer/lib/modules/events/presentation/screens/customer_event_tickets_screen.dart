import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:shared_payment/shared_payment.dart';
import 'package:uuid/uuid.dart';

import '../../../../core/di/providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../widgets/event_image.dart';

final eventTicketsApiProvider =
    Provider<Dio>((ref) => ref.watch(apiClientProvider).dio);

class CustomerEventTicketsScreen extends ConsumerStatefulWidget {
  const CustomerEventTicketsScreen({super.key});
  @override
  ConsumerState<CustomerEventTicketsScreen> createState() =>
      _CustomerEventTicketsScreenState();
}

class _CustomerEventTicketsScreenState
    extends ConsumerState<CustomerEventTicketsScreen>
    with SingleTickerProviderStateMixin {
  List<Map<String, dynamic>> _tickets = const [];
  List<Map<String, dynamic>> _invitations = const [];
  bool _loading = true;
  String? _error;
  String? _invitationError;
  late final TabController _tabController;
  final Map<String, String> _invitationIdempotencyKeys = {};

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _load();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final response = await ref
          .read(eventTicketsApiProvider)
          .get<Object?>('/v1/events/tickets');
      final body = response.data! as Map<String, dynamic>;
      final data = body['data']! as List<dynamic>;
      if (mounted) setState(() => _tickets = data.cast<Map<String, dynamic>>());
    } catch (_) {
      if (mounted)
        setState(() => _error = 'Could not load your event tickets.');
    } finally {
      try {
        final response = await ref
            .read(eventTicketsApiProvider)
            .get<Object?>('/v1/events/customer/invitations');
        final body = response.data! as Map<String, dynamic>;
        final rows = body['data'] as List<dynamic>? ?? const [];
        if (mounted) {
          setState(() {
            _invitations = rows.cast<Map<String, dynamic>>();
            _invitationError = null;
          });
        }
      } catch (_) {
        if (mounted) {
          setState(() =>
              _invitationError = 'Could not load your event invitations.');
        }
      }
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _acceptInvitation(Map<String, dynamic> invitation) async {
    final invitationId = invitation['invitation_id']?.toString();
    if (invitationId == null || invitationId.isEmpty) return;
    try {
      await ref.read(eventTicketsApiProvider).post<Object?>(
            '/v1/events/customer/invitations/$invitationId/accept',
            options: Options(headers: {
              'Idempotency-Key': _invitationIdempotencyKeys.putIfAbsent(
                invitationId,
                () => const Uuid().v4(),
              ),
            }),
          );
      await _load();
      if (mounted) {
        _tabController.animateTo(0);
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('Invitation accepted. Your ticket is ready.')));
      }
    } on DioException catch (error) {
      final body = error.response?.data;
      final message = body is Map
          ? (body['message'] ??
                  (body['error'] is Map
                      ? (body['error'] as Map)['message']
                      : null))
              ?.toString()
          : null;
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(message ?? 'Could not accept invitation.')),
        );
      }
    }
  }

  Future<void> _replaceCredential(String ticketId) async {
    try {
      final token = await ref
          .read(stepUpServiceProvider)
          .acquireToken(scope: 'event_ticket.credential.replace:$ticketId');
      await ref.read(eventTicketsApiProvider).post<Object?>(
          '/v1/events/tickets/$ticketId/replace-credential',
          data: {'step_up_token': token},
          options: Options(headers: {'Idempotency-Key': const Uuid().v4()}));
      await _load();
    } catch (_) {
      if (mounted)
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('Credential replacement was not completed.')));
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('My Tickets'),
          bottom: TabBar(
            controller: _tabController,
            tabs: const [
              Tab(text: 'Tickets'),
              Tab(text: 'Invitations'),
            ],
          ),
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : TabBarView(
                controller: _tabController,
                children: [
                  _ticketList(),
                  _invitationList(),
                ],
              ),
      );

  Widget _ticketList() => _error != null
      ? Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 14),
              FilledButton(onPressed: _load, child: const Text('Try again')),
            ]),
          ),
        )
      : RefreshIndicator(
          onRefresh: _load,
          child: _tickets.isEmpty
              ? ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: [
                    const SizedBox(height: 110),
                    const Icon(Icons.confirmation_number_outlined,
                        size: 44, color: AppColors.textSecondary),
                    const SizedBox(height: 14),
                    Center(
                        child: Text('No tickets yet',
                            style: Theme.of(context).textTheme.titleLarge)),
                    const SizedBox(height: 6),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 32),
                      child: Text(
                        'Tickets for events you register for will appear here.',
                        textAlign: TextAlign.center,
                        style: Theme.of(context)
                            .textTheme
                            .bodyMedium
                            ?.copyWith(color: AppColors.textSecondary),
                      ),
                    ),
                  ],
                )
              : ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(20, 10, 20, 24),
                  children: [
                    Text('Your event passes',
                        style: Theme.of(context).textTheme.headlineSmall),
                    const SizedBox(height: 5),
                    Text(
                        '${_tickets.length} ${_tickets.length == 1 ? 'ticket' : 'tickets'}',
                        style: Theme.of(context)
                            .textTheme
                            .bodyMedium
                            ?.copyWith(color: AppColors.textSecondary)),
                    const SizedBox(height: 18),
                    ..._tickets.map(_ticketCard),
                  ],
                ),
        );

  Widget _invitationList() => RefreshIndicator(
        onRefresh: _load,
        child: _invitationError != null
            ? ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  const SizedBox(height: 100),
                  Center(
                      child:
                          Text(_invitationError!, textAlign: TextAlign.center)),
                  Center(
                    child: TextButton(
                        onPressed: _load, child: const Text('Try again')),
                  ),
                ],
              )
            : _invitations.isEmpty
                ? ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    children: [
                      const SizedBox(height: 110),
                      const Icon(Icons.mail_outline_rounded,
                          size: 44, color: AppColors.textSecondary),
                      const SizedBox(height: 14),
                      Center(
                          child: Text('No event invitations',
                              style: Theme.of(context).textTheme.titleLarge)),
                      const SizedBox(height: 6),
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 32),
                        child: Text(
                          'Invitations addressed to your OleKard account will appear here.',
                          textAlign: TextAlign.center,
                          style: Theme.of(context)
                              .textTheme
                              .bodyMedium
                              ?.copyWith(color: AppColors.textSecondary),
                        ),
                      ),
                    ],
                  )
                : ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(20, 10, 20, 24),
                    children: [
                      Text('Invitations for you',
                          style: Theme.of(context).textTheme.headlineSmall),
                      const SizedBox(height: 14),
                      ..._invitations.map(_invitationCard),
                    ],
                  ),
      );

  Widget _invitationCard(Map<String, dynamic> invitation) {
    final start = DateTime.tryParse(invitation['starts_at']?.toString() ?? '');
    final salesStart =
        DateTime.tryParse(invitation['sales_start_at']?.toString() ?? '');
    final bookingOpen =
        salesStart == null || !salesStart.isAfter(DateTime.now());
    final venue = invitation['venue_name']?.toString() ?? '';
    final city = invitation['venue_city']?.toString() ?? '';
    return Card(
      margin: const EdgeInsets.only(bottom: 14),
      clipBehavior: Clip.antiAlias,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                EventImage(
                  imageUrl: invitation['image_url']?.toString(),
                  eventId: invitation['event_id']?.toString(),
                  width: 76,
                  height: 76,
                  label: 'Invitation event image',
                  iconSize: 24,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(invitation['event_title']?.toString() ?? 'Event',
                          style: Theme.of(context).textTheme.titleMedium),
                      if (start != null) _DateLine(date: start),
                      if (venue.isNotEmpty || city.isNotEmpty)
                        Text(
                          [venue, city]
                              .where((value) => value.isNotEmpty)
                              .join(' · '),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style:
                              Theme.of(context).textTheme.bodySmall?.copyWith(
                                    color: AppColors.textSecondary,
                                  ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Text('Invitation · ${invitation['category_name'] ?? 'Ticket'}',
                style: Theme.of(context).textTheme.labelLarge),
            const SizedBox(height: 4),
            Text(
              bookingOpen
                  ? 'Valid until ${_formatDate(DateTime.parse(invitation['expires_at'].toString()))}'
                  : 'Booking opens ${_formatDate(salesStart)}',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
            ),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed:
                    bookingOpen ? () => _acceptInvitation(invitation) : null,
                child: const Text('Accept invitation'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _ticketCard(Map<String, dynamic> ticket) {
    final status = ticket['status']?.toString() ?? '';
    final start =
        DateTime.tryParse(ticket['event_starts_at']?.toString() ?? '');
    return Card(
      margin: const EdgeInsets.only(bottom: 14),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(
            builder: (_) => _TicketDetailScreen(
                ticket: ticket,
                onReplace: () =>
                    _replaceCredential(ticket['ticket_id'] as String)))),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          EventImage(
              imageUrl: ticket['image_url']?.toString(),
              eventId: ticket['event_id']?.toString(),
              label: 'Event image',
              width: double.infinity,
              height: 140),
          Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                              child: Text(
                                  ticket['event_title']?.toString() ?? 'Event',
                                  style:
                                      Theme.of(context).textTheme.titleLarge)),
                          const SizedBox(width: 8),
                          _StatusPill(status: status),
                        ]),
                    if (start != null) ...[
                      const SizedBox(height: 10),
                      _DateLine(date: start)
                    ],
                    const SizedBox(height: 6),
                    Text(ticket['category']?.toString() ?? 'Ticket',
                        style: Theme.of(context)
                            .textTheme
                            .bodyMedium
                            ?.copyWith(color: AppColors.textSecondary)),
                    const SizedBox(height: 12),
                    Row(children: [
                      const Icon(Icons.qr_code_2_rounded,
                          size: 19, color: AppColors.primary),
                      const SizedBox(width: 7),
                      Expanded(
                          child: Text('Show ticket at the entrance',
                              style: Theme.of(context)
                                  .textTheme
                                  .labelLarge
                                  ?.copyWith(color: AppColors.primary))),
                      const Spacer(),
                      const Icon(Icons.chevron_right_rounded,
                          color: AppColors.textSecondary)
                    ]),
                  ])),
        ]),
      ),
    );
  }
}

class _TicketDetailScreen extends StatelessWidget {
  const _TicketDetailScreen({required this.ticket, required this.onReplace});
  final Map<String, dynamic> ticket;
  final Future<void> Function() onReplace;

  @override
  Widget build(BuildContext context) {
    final status = ticket['status']?.toString() ?? '';
    final credential = ticket['credential']?.toString() ?? '';
    final start =
        DateTime.tryParse(ticket['event_starts_at']?.toString() ?? '');
    final admitted = status == 'admitted';
    final unavailable = status == 'cancelled' || status == 'revoked';
    return Scaffold(
      appBar: AppBar(title: const Text('Ticket details')),
      body: ListView(
          padding: const EdgeInsets.fromLTRB(22, 12, 22, 28),
          children: [
            Text(ticket['event_title']?.toString() ?? 'Event',
                style: Theme.of(context).textTheme.headlineSmall),
            const SizedBox(height: 10),
            if (start != null) _DateLine(date: start),
            const SizedBox(height: 14),
            Row(children: [
              Expanded(
                child: Text(ticket['category']?.toString() ?? 'Ticket',
                    style: Theme.of(context).textTheme.bodyLarge),
              ),
              const Spacer(),
              _StatusPill(status: status)
            ]),
            const SizedBox(height: 22),
            if (unavailable)
              Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(
                      color: AppColors.errorBg,
                      borderRadius: BorderRadius.circular(18)),
                  child: Row(children: [
                    const Icon(Icons.event_busy_rounded,
                        color: AppColors.error),
                    const SizedBox(width: 12),
                    Expanded(
                        child: Text(
                            status == 'cancelled'
                                ? 'This event was cancelled. This ticket cannot be used.'
                                : 'This ticket is no longer valid.',
                            style: const TextStyle(color: AppColors.error)))
                  ]))
            else ...[
              Container(
                padding: const EdgeInsets.all(24),
                decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(22),
                    border: Border.all(color: AppColors.outline)),
                child: Column(children: [
                  QrImageView(
                      data: credential,
                      size: 240,
                      backgroundColor: Colors.white,
                      semanticsLabel: 'Event ticket QR code'),
                  const SizedBox(height: 12),
                  Text(admitted ? 'Entry recorded' : 'Ready for entry',
                      style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 4),
                  Text(
                      admitted
                          ? 'This ticket has already been checked in.'
                          : 'Present this code to event staff at the gate.',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodySmall),
                ]),
              ),
              if (!admitted) ...[
                const SizedBox(height: 16),
                OutlinedButton.icon(
                    onPressed: () async {
                      await onReplace();
                      if (context.mounted) Navigator.of(context).pop();
                    },
                    icon: const Icon(Icons.refresh_rounded),
                    label: const Text('Replace ticket code')),
              ],
            ],
          ]),
    );
  }
}

class _DateLine extends StatelessWidget {
  const _DateLine({required this.date});
  final DateTime date;
  @override
  Widget build(BuildContext context) => Row(children: [
        const Icon(Icons.calendar_today_outlined,
            size: 18, color: AppColors.textSecondary),
        const SizedBox(width: 8),
        Expanded(
            child: Text(_formatDate(date),
                style: Theme.of(context)
                    .textTheme
                    .bodyMedium
                    ?.copyWith(color: AppColors.textSecondary)))
      ]);
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.status});
  final String status;
  @override
  Widget build(BuildContext context) {
    final ok = status == 'admitted' || status == 'active' || status == 'issued';
    final color = ok
        ? AppColors.success
        : status == 'cancelled' || status == 'revoked'
            ? AppColors.error
            : AppColors.primary;
    final bg = ok
        ? AppColors.successBg
        : status == 'cancelled' || status == 'revoked'
            ? AppColors.errorBg
            : AppColors.infoBg;
    final label = status == 'admitted'
        ? 'Used'
        : status == 'cancelled'
            ? 'Cancelled'
            : status == 'revoked'
                ? 'Revoked'
                : 'Upcoming';
    return Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration:
            BoxDecoration(color: bg, borderRadius: BorderRadius.circular(100)),
        child: Text(label,
            style: TextStyle(
                color: color, fontSize: 12, fontWeight: FontWeight.w600)));
  }
}

String _formatDate(DateTime value) {
  final date = value.toLocal();
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
  return '${months[date.month - 1]} ${date.day}, ${date.year} · ${date.hour.toString().padLeft(2, '0')}:${date.minute.toString().padLeft(2, '0')}';
}
