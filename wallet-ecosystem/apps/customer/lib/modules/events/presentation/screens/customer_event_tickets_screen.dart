import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:dio/dio.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:shared_payment/shared_payment.dart';
import 'package:uuid/uuid.dart';

import '../../../../core/di/providers.dart';

final eventTicketsApiProvider =
    Provider<Dio>((ref) => ref.watch(apiClientProvider).dio);

class CustomerEventTicketsScreen extends ConsumerStatefulWidget {
  const CustomerEventTicketsScreen({super.key});

  @override
  ConsumerState<CustomerEventTicketsScreen> createState() =>
      _CustomerEventTicketsScreenState();
}

class _CustomerEventTicketsScreenState
    extends ConsumerState<CustomerEventTicketsScreen> {
  List<Map<String, dynamic>> _tickets = const [];
  bool _loading = true;
  String? _error;

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
      final response = await ref.read(eventTicketsApiProvider).get<Object?>(
            '/v1/events/tickets',
          );
      final body = response.data! as Map<String, dynamic>;
      final data = body['data']! as List<dynamic>;
      if (!mounted) return;
      setState(() => _tickets = data.cast<Map<String, dynamic>>());
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'Could not load your event tickets.');
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _replaceCredential(String ticketId) async {
    try {
      final token = await ref.read(stepUpServiceProvider).acquireToken(
            scope: 'event_ticket.credential.replace:$ticketId',
          );
      await ref.read(eventTicketsApiProvider).post<Object?>(
            '/v1/events/tickets/$ticketId/replace-credential',
            data: {'step_up_token': token},
            options: Options(headers: {'Idempotency-Key': const Uuid().v4()}),
          );
      await _load();
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('Credential replacement was not completed.')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('My Event Tickets')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(_error!),
                      const SizedBox(height: 12),
                      FilledButton(
                          onPressed: _load, child: const Text('Retry')),
                    ],
                  ),
                )
              : _tickets.isEmpty
                  ? const Center(
                      child: Text('Your event tickets will appear here.'))
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.builder(
                        padding: const EdgeInsets.all(16),
                        itemCount: _tickets.length,
                        itemBuilder: (context, index) =>
                            _ticketCard(_tickets[index]),
                      ),
                    ),
    );
  }

  Widget _ticketCard(Map<String, dynamic> ticket) {
    final credential = ticket['credential'] as String;
    final admitted = ticket['status'] == 'admitted';
    final cancelled = ticket['status'] == 'cancelled';
    final revoked = ticket['status'] == 'revoked';
    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            Text(ticket['event_title'] as String,
                style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 4),
            Text(ticket['category'] as String),
            const SizedBox(height: 12),
            if (cancelled) ...[
              const Icon(Icons.event_busy, size: 48),
              const SizedBox(height: 8),
              const Text(
                  'This event was cancelled. This ticket cannot be used.'),
            ] else if (revoked) ...[
              const Icon(Icons.block, size: 48),
              const SizedBox(height: 8),
              const Text('This ticket is no longer valid.'),
            ] else ...[
              QrImageView(data: credential, size: 220),
              SelectableText(credential,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.labelSmall),
              const SizedBox(height: 8),
              Text(admitted ? 'Entry recorded' : 'Ready for entry'),
            ],
            if (!admitted && !cancelled && !revoked)
              TextButton.icon(
                onPressed: () =>
                    _replaceCredential(ticket['ticket_id'] as String),
                icon: const Icon(Icons.refresh),
                label: const Text('Replace ticket credential'),
              ),
          ],
        ),
      ),
    );
  }
}
