import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
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
                  eventCategory: invitation['event_category']?.toString(),
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
    final canShowQr = status == 'valid';
    final ticketId = ticket['ticket_id']?.toString() ?? '';

    void openTicket() {
      Navigator.of(context).push(MaterialPageRoute<void>(
        builder: (_) => _TicketDetailScreen(
          ticket: ticket,
          api: ref.read(eventTicketsApiProvider),
          onReplace: () => _replaceCredential(ticketId),
        ),
      ));
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 14),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: openTicket,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 14, 8, 12),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                EventImage(
                  imageUrl: ticket['image_url']?.toString(),
                  eventId: ticket['event_id']?.toString(),
                  eventCategory: ticket['event_category']?.toString(),
                  label: 'Artwork for ${ticket['event_title'] ?? 'event'}',
                  width: 72,
                  height: 72,
                  borderRadius: 12,
                  iconSize: 26,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (start != null)
                        Text(
                          _formatDate(start),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context)
                              .textTheme
                              .labelMedium
                              ?.copyWith(color: AppColors.textSecondary),
                        ),
                      const SizedBox(height: 4),
                      Text(
                        ticket['event_title']?.toString() ?? 'Event',
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      if (status != 'valid') ...[
                        const SizedBox(height: 5),
                        _StatusPill(status: status),
                      ],
                    ],
                  ),
                ),
                const SizedBox(width: 4),
                if (canShowQr)
                  IconButton(
                    tooltip: 'Show ticket QR',
                    onPressed: openTicket,
                    icon: const Icon(Icons.qr_code_2_rounded,
                        color: AppColors.primary),
                  ),
              ],
            ),
          ),
          CustomPaint(
            painter: _TicketDividerPainter(
              lineColor: AppColors.outline,
              notchColor: Theme.of(context).scaffoldBackgroundColor,
            ),
            child: const SizedBox(height: 18, width: double.infinity),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Padding(
                    padding: EdgeInsets.only(top: 2),
                    child: Icon(Icons.place_outlined,
                        size: 18, color: AppColors.textSecondary),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      _ticketLocation(ticket),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AppColors.textSecondary,
                          ),
                    ),
                  ),
                ]),
                if ((ticket['category']?.toString() ?? '').isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(left: 26, top: 5),
                    child: Text(ticket['category'].toString(),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.labelSmall),
                  ),
              ],
            ),
          ),
        ]),
      ),
    );
  }
}

class _TicketDetailScreen extends ConsumerStatefulWidget {
  const _TicketDetailScreen({
    required this.ticket,
    required this.api,
    required this.onReplace,
  });

  final Map<String, dynamic> ticket;
  final Dio api;
  final Future<void> Function() onReplace;

  @override
  ConsumerState<_TicketDetailScreen> createState() =>
      _TicketDetailScreenState();
}

class _TicketDetailScreenState extends ConsumerState<_TicketDetailScreen> {
  late Map<String, dynamic> _ticket;
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _ticket = Map<String, dynamic>.from(widget.ticket);
    if (_ticket['status'] == 'valid') {
      _loadCredential();
    }
  }

  Future<void> _loadCredential() async {
    final ticketId = _ticket['ticket_id']?.toString();
    if (ticketId == null || ticketId.isEmpty) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final response = await widget.api.get<Object?>(
        '/v1/events/tickets/$ticketId',
      );
      final body = response.data as Map<String, dynamic>;
      final detail = Map<String, dynamic>.from(body['data'] as Map);
      if (mounted) setState(() => _ticket = detail);
    } on DioException {
      if (mounted) setState(() => _error = 'Could not load the ticket QR.');
    } catch (_) {
      if (mounted) setState(() => _error = 'Could not load the ticket QR.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final status = _ticket['status']?.toString() ?? '';
    final credential = _ticket['credential']?.toString() ?? '';
    final holderName = _ticket['ticket_holder_name']?.toString().trim() ?? '';
    final start =
        DateTime.tryParse(_ticket['event_starts_at']?.toString() ?? '');
    final canShowQr = status == 'valid' && credential.isNotEmpty;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Ticket details'),
        centerTitle: true,
      ),
      body: SafeArea(
        top: false,
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(22, 10, 22, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (start != null)
                Text(
                  _formatDate(start),
                  textAlign: TextAlign.center,
                  style: Theme.of(context)
                      .textTheme
                      .titleSmall
                      ?.copyWith(color: AppColors.textSecondary),
                ),
              const SizedBox(height: 10),
              Text(
                _ticket['event_title']?.toString() ?? 'Event',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              const SizedBox(height: 10),
              Center(child: _StatusPill(status: status)),
              const SizedBox(height: 22),
              if (canShowQr)
                LayoutBuilder(builder: (context, constraints) {
                  final qrSize = (constraints.maxWidth - 40).clamp(180, 300);
                  return Center(
                    child: Container(
                      padding: const EdgeInsets.all(20),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(22),
                        border: Border.all(color: AppColors.outline),
                      ),
                      child: QrImageView(
                        data: credential,
                        size: qrSize.toDouble(),
                        backgroundColor: Colors.white,
                        semanticsLabel: 'Event ticket QR code',
                      ),
                    ),
                  );
                })
              else if (_loading)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 92),
                  child: Center(child: CircularProgressIndicator()),
                )
              else if (_error != null)
                _TicketMessage(
                  icon: Icons.qr_code_2_rounded,
                  message: _error!,
                  action: TextButton.icon(
                    onPressed: _loadCredential,
                    icon: const Icon(Icons.refresh_rounded),
                    label: const Text('Try again'),
                  ),
                )
              else
                _TicketMessage(
                  icon: status == 'cancelled' || status == 'revoked'
                      ? Icons.event_busy_rounded
                      : status == 'admitted'
                          ? Icons.verified_rounded
                          : Icons.info_outline_rounded,
                  message: switch (status) {
                    'cancelled' =>
                      'This event was cancelled. This ticket cannot be used.',
                    'revoked' => 'This ticket is no longer valid.',
                    'admitted' => 'This ticket has already been used.',
                    'expired' => 'This ticket has expired.',
                    _ => 'This ticket is not available for admission.',
                  },
                ),
              if (canShowQr) ...[
                const SizedBox(height: 18),
                if (holderName.isNotEmpty) ...[
                  Text(holderName,
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 8),
                ],
                Text(
                  'Show this QR code at the entrance.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                const SizedBox(height: 12),
                if ((_ticket['category']?.toString() ?? '').isNotEmpty)
                  Text(
                    _ticket['category'].toString(),
                    textAlign: TextAlign.center,
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: AppColors.textSecondary),
                  ),
                if (_ticketLocation(_ticket).isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    _ticketLocation(_ticket),
                    textAlign: TextAlign.center,
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: AppColors.textSecondary),
                  ),
                ],
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  onPressed: _loading
                      ? null
                      : () async {
                          await widget.onReplace();
                          if (context.mounted) Navigator.of(context).pop();
                        },
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('Replace ticket code'),
                ),
              ],
            ],
          ),
        ),
      ),
      bottomNavigationBar: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 10, 20, 12),
          child: Tooltip(
            message: 'Close ticket details',
            child: OutlinedButton.icon(
              onPressed: () => Navigator.of(context).maybePop(),
              icon: const Icon(Icons.close_rounded),
              label: const Text('Close'),
            ),
          ),
        ),
      ),
    );
  }
}

String _ticketLocation(Map<String, dynamic> ticket) {
  final venue = ticket['venue_name']?.toString().trim() ?? '';
  final city = ticket['venue_city']?.toString().trim() ?? '';
  if (venue.isEmpty && city.isEmpty) return 'Venue details to be confirmed';
  if (venue.isEmpty) return city;
  if (city.isEmpty) return venue;
  return '$venue \u00B7 $city';
}

class _TicketMessage extends StatelessWidget {
  const _TicketMessage(
      {required this.icon, required this.message, this.action});

  final IconData icon;
  final String message;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: const EdgeInsets.all(24),
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surface,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: AppColors.outline),
        ),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 34, color: AppColors.textSecondary),
          const SizedBox(height: 12),
          Text(message, textAlign: TextAlign.center),
          if (action != null) ...[const SizedBox(height: 8), action!],
        ]),
      );
}

class _TicketDividerPainter extends CustomPainter {
  const _TicketDividerPainter(
      {required this.lineColor, required this.notchColor});

  final Color lineColor;
  final Color notchColor;

  @override
  void paint(Canvas canvas, Size size) {
    final centerY = size.height / 2;
    final line = Paint()
      ..color = lineColor
      ..strokeWidth = 1;
    for (var x = 12.0; x < size.width - 12; x += 8) {
      canvas.drawLine(Offset(x, centerY), Offset(x + 4, centerY), line);
    }
    final notch = Paint()..color = notchColor;
    canvas.drawCircle(Offset(0, centerY), 9, notch);
    canvas.drawCircle(Offset(size.width, centerY), 9, notch);
  }

  @override
  bool shouldRepaint(_TicketDividerPainter oldDelegate) =>
      oldDelegate.lineColor != lineColor ||
      oldDelegate.notchColor != notchColor;
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
    final isAvailable =
        status == 'valid' || status == 'active' || status == 'issued';
    final isUnavailable =
        status == 'cancelled' || status == 'revoked' || status == 'expired';
    final color = isAvailable
        ? AppColors.success
        : isUnavailable
            ? AppColors.error
            : AppColors.primary;
    final bg = isAvailable
        ? AppColors.successBg
        : isUnavailable
            ? AppColors.errorBg
            : AppColors.infoBg;
    final label = switch (status) {
      'admitted' => 'Used',
      'cancelled' => 'Cancelled',
      'revoked' => 'Revoked',
      'expired' => 'Expired',
      'valid' || 'active' || 'issued' => 'Upcoming',
      '' => 'Unavailable',
      _ => _readableStatus(status),
    };
    return Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration:
            BoxDecoration(color: bg, borderRadius: BorderRadius.circular(100)),
        child: Text(label,
            style: TextStyle(
                color: color, fontSize: 12, fontWeight: FontWeight.w600)));
  }
}

String _readableStatus(String status) => status
    .split(RegExp(r'[_\s]+'))
    .where((part) => part.isNotEmpty)
    .map((part) => '${part[0].toUpperCase()}${part.substring(1)}')
    .join(' ');

String _formatDate(DateTime value) {
  final locale = Intl.getCurrentLocale();
  return DateFormat.yMMMd(locale).add_jm().format(value.toLocal());
}
