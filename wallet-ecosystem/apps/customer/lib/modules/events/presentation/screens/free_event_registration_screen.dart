import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:uuid/uuid.dart';

import '../../../../core/di/providers.dart';

final freeEventRegistrationDioProvider = Provider<Dio>(
  (ref) => ref.watch(apiClientProvider).dio,
);

/// Invite tokens are entered in the authenticated app and never placed in a URL.
class FreeEventRegistrationScreen extends ConsumerStatefulWidget {
  const FreeEventRegistrationScreen({
    super.key,
    this.eventId,
    this.categoryId,
    this.eventTitle,
    this.categoryName,
  });

  final String? eventId;
  final String? categoryId;
  final String? eventTitle;
  final String? categoryName;

  @override
  ConsumerState<FreeEventRegistrationScreen> createState() =>
      _FreeEventRegistrationScreenState();
}

class _FreeEventRegistrationScreenState
    extends ConsumerState<FreeEventRegistrationScreen> {
  late final _eventId = TextEditingController(text: widget.eventId ?? '');
  late final _categoryId = TextEditingController(text: widget.categoryId ?? '');
  final _invitation = TextEditingController();
  bool _busy = false;
  String? _message;
  String? _idempotencyKey;

  @override
  void dispose() {
    _eventId.dispose();
    _categoryId.dispose();
    _invitation.dispose();
    super.dispose();
  }

  Future<void> _register() async {
    if (_eventId.text.isEmpty || _categoryId.text.isEmpty) {
      setState(() => _message = 'Select an event and ticket category first.');
      return;
    }
    if (widget.eventId != null && _invitation.text.trim().isEmpty) {
      setState(() => _message =
          'Enter the invitation token provided by the event organiser.');
      return;
    }
    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      final token = _invitation.text.trim();
      final idempotencyKey = _idempotencyKey ??= const Uuid().v4();
      await ref.read(freeEventRegistrationDioProvider).post<Object?>(
            '/v1/events/${_eventId.text.trim()}/free-registrations',
            data: {
              'category_id': _categoryId.text.trim(),
              if (token.isNotEmpty) 'invitation_token': token,
            },
            options: Options(headers: {'Idempotency-Key': idempotencyKey}),
          );
      if (!mounted) return;
      context.go('/events/tickets');
    } on DioException catch (error) {
      if (!mounted) return;
      final message = error.response?.data is Map<String, dynamic>
          ? (error.response!.data as Map<String, dynamic>)['message']
          : null;
      setState(() => _message = message is String
          ? message
          : 'This invitation or free allocation is unavailable.');
    } catch (_) {
      if (mounted) {
        setState(() => _message = 'Registration could not be completed.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Free event registration')),
        body: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(widget.eventTitle == null
                ? 'Enter the invitation token for your complimentary event ticket. Invitation tokens are private and are never placed in a URL.'
                : 'Enter the invitation token for ${widget.eventTitle} · ${widget.categoryName ?? 'Complimentary ticket'}.'),
            const SizedBox(height: 16),
            if (widget.eventId == null)
              TextField(
                controller: _eventId,
                onChanged: (_) => _idempotencyKey = null,
                decoration: const InputDecoration(labelText: 'Event ID'),
              ),
            if (widget.categoryId == null)
              TextField(
                controller: _categoryId,
                onChanged: (_) => _idempotencyKey = null,
                decoration:
                    const InputDecoration(labelText: 'Ticket category ID'),
              ),
            TextField(
              controller: _invitation,
              onChanged: (_) => _idempotencyKey = null,
              obscureText: true,
              autocorrect: false,
              enableSuggestions: false,
              decoration: const InputDecoration(
                labelText: 'Invitation token',
              ),
            ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: _busy ? null : _register,
              child: Text(_busy ? 'Registering…' : 'Redeem invitation'),
            ),
            if (_message != null) ...[
              const SizedBox(height: 12),
              Text(_message!),
            ],
          ],
        ),
      );
}
