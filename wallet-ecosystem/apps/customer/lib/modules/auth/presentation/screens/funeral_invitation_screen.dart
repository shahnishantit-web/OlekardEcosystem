import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:dio/dio.dart';

import '../../../../core/di/providers.dart';
import '../../../../core/security/secure_storage_service.dart';

class FuneralInvitationScreen extends ConsumerStatefulWidget {
  const FuneralInvitationScreen({super.key, required this.token});

  final String? token;

  @override
  ConsumerState<FuneralInvitationScreen> createState() =>
      _FuneralInvitationScreenState();
}

class _FuneralInvitationScreenState
    extends ConsumerState<FuneralInvitationScreen> {
  final _storage = const SecureStorageService();
  bool _loading = false;
  bool _claimed = false;
  bool _initialized = false;
  String? _error;
  Map<String, dynamic>? _claimResult;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _initialize());
  }

  Future<void> _initialize() async {
    if (_initialized) return;
    _initialized = true;
    final token = widget.token?.trim();
    if (token != null && token.isNotEmpty) {
      await _storage.writePendingFuneralInvitation(token);
    }
    if (!mounted) return;
    final savedToken = await _storage.readPendingFuneralInvitation();
    if (savedToken == null || savedToken.isEmpty) {
      setState(() => _error =
          'This invitation link is missing or has expired. Ask your community manager for a new link.');
      return;
    }
    if (ref.read(authProvider).isAuthenticated) {
      await _claimInvitation(savedToken);
    }
  }

  Future<void> _claimInvitation([String? token]) async {
    if (_loading || _claimed) return;
    final inviteToken = token ?? await _storage.readPendingFuneralInvitation();
    if (inviteToken == null || inviteToken.isEmpty) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final response = await ref.read(apiClientProvider).dio.post(
        '/api/v1/community/funeral/invitations/claim',
        data: {'token': inviteToken},
      );
      final body = response.data;
      final data = body is Map && body['data'] is Map
          ? Map<String, dynamic>.from(body['data'] as Map)
          : <String, dynamic>{};
      await _storage.deletePendingFuneralInvitation();
      if (!mounted) return;
      setState(() {
        _claimed = true;
        _claimResult = data;
      });
    } catch (error) {
      if (!mounted) return;
      final responseData = error is DioException ? error.response?.data : null;
      final message = responseData is Map
          ? (responseData['error'] is Map
              ? responseData['error']['message']?.toString()
              : null)
          : null;
      setState(() => _error = message ??
          'We could not verify this invitation. Sign in or register with the phone number that received it, then try again.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final authenticated =
        ref.watch(authProvider.select((state) => state.isAuthenticated));
    ref.listen(authProvider.select((state) => state.isAuthenticated),
        (previous, next) {
      if (next && previous != true) _claimInvitation();
    });
    final tokenExists = widget.token?.isNotEmpty == true;

    return Scaffold(
      appBar: AppBar(title: const Text('Join a funeral assistance household')),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 520),
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: _claimed
                  ? _buildClaimed(context)
                  : Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const Icon(Icons.volunteer_activism_outlined, size: 48),
                        const SizedBox(height: 16),
                        Text('You have a community contribution invitation',
                            style: Theme.of(context).textTheme.titleLarge,
                            textAlign: TextAlign.center),
                        const SizedBox(height: 12),
                        const Text(
                            'Create an OleKard account or sign in with the phone number that received this invitation. After phone verification, OleKard will link your account to the household and show the collection details.'),
                        if (_error != null) ...[
                          const SizedBox(height: 16),
                          Text(_error!,
                              style: TextStyle(
                                  color: Theme.of(context).colorScheme.error)),
                        ],
                        const SizedBox(height: 20),
                        if (_loading)
                          const Center(child: CircularProgressIndicator())
                        else if (!authenticated) ...[
                          FilledButton(
                              onPressed: tokenExists
                                  ? () => context.go('/register')
                                  : null,
                              child: const Text('Join OleKard')),
                          const SizedBox(height: 8),
                          OutlinedButton(
                              onPressed: tokenExists
                                  ? () => context.go('/login')
                                  : null,
                              child: const Text(
                                  'I already have an OleKard account')),
                        ] else
                          FilledButton(
                              onPressed: () => _claimInvitation(),
                              child: const Text('Verify invitation')),
                      ],
                    ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildClaimed(BuildContext context) {
    final data = _claimResult ?? const <String, dynamic>{};
    final amount = (int.tryParse('${data['amount_minor'] ?? 0}') ?? 0) / 100;
    final currency = data['currency_code']?.toString() ?? 'BWP';
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Icon(Icons.check_circle_outline, color: Colors.green, size: 52),
        const SizedBox(height: 16),
        Text('Household linked',
            style: Theme.of(context).textTheme.titleLarge,
            textAlign: TextAlign.center),
        const SizedBox(height: 12),
        Text(
            '${data['household_reference'] ?? 'Your household'} is now linked to your OleKard account.'),
        const SizedBox(height: 8),
        Text(
            '${data['community_name'] ?? 'Community'} · ${data['collection_purpose'] ?? 'Funeral assistance'} · $currency ${amount.toStringAsFixed(2)}'),
        const SizedBox(height: 20),
        FilledButton(
            onPressed: () => context
                .go('/services/funeral?collection_id=${data['collection_id']}'),
            child: const Text('View contribution request')),
      ],
    );
  }
}
