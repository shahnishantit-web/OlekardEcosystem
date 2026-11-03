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
  const FreeEventRegistrationScreen({super.key});

  @override
  ConsumerState<FreeEventRegistrationScreen> createState() =>
      _FreeEventRegistrationScreenState();
}

class _FreeEventRegistrationScreenState
    extends ConsumerState<FreeEventRegistrationScreen> {
  final _eventId = TextEditingController();
  final _categoryId = TextEditingController();
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
      setState(() => _message = 'Enter the event and free category details.');
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
            const Text(
              'Registration is available for published events with an active free allocation. An invitation token is optional for open free categories and is never sent in a URL.',
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _eventId,
              onChanged: (_) => _idempotencyKey = null,
              decoration: const InputDecoration(labelText: 'Event ID'),
            ),
            TextField(
              controller: _categoryId,
              onChanged: (_) => _idempotencyKey = null,
              decoration: const InputDecoration(labelText: 'Free category ID'),
            ),
            TextField(
              controller: _invitation,
              onChanged: (_) => _idempotencyKey = null,
              obscureText: true,
              autocorrect: false,
              enableSuggestions: false,
              decoration: const InputDecoration(
                labelText: 'Invitation token (if required)',
              ),
            ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: _busy ? null : _register,
              child: Text(_busy ? 'Registering…' : 'Register for free'),
            ),
            if (_message != null) ...[
              const SizedBox(height: 12),
              Text(_message!),
            ],
          ],
        ),
      );
}
