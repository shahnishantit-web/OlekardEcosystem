import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/button.dart';
import '../../../../shared/widgets/loading.dart';
import '../../models/auth_models.dart';
import '../../services/auth_api_service.dart';
import '../../state/auth_state_notifier.dart';

/// US-AUTH-026 / US-AUTH-027 — Session management screen.
/// Lists all active sessions for the authenticated user; revoke individual
/// or all sessions. No raw IP is displayed (as per spec — only device metadata).
class SessionsScreen extends ConsumerStatefulWidget {
  const SessionsScreen({super.key});

  @override
  ConsumerState<SessionsScreen> createState() => _SessionsScreenState();
}

class _SessionsScreenState extends ConsumerState<SessionsScreen> {
  List<SessionInfo>? _sessions;
  bool _isLoading = true;
  String? _error;
  final Set<String> _revoking = {};

  AuthApiService get _api => ref.read(authApiServiceProvider);

  @override
  void initState() {
    super.initState();
    _loadSessions();
  }

  Future<void> _loadSessions() async {
    setState(() {
      _isLoading = true;
      _error = null;
    });
    try {
      final sessions = await _api.listSessions();
      setState(() {
        _sessions = sessions;
        _isLoading = false;
      });
    } on AuthApiException catch (e) {
      setState(() {
        _error = e.message;
        _isLoading = false;
      });
    }
  }

  Future<void> _revokeSession(String sessionId) async {
    setState(() => _revoking.add(sessionId));
    try {
      await _api.revokeSession(sessionId);
      setState(() {
        _sessions?.removeWhere((s) => s.sessionId == sessionId);
        _revoking.remove(sessionId);
      });
    } on AuthApiException catch (e) {
      setState(() {
        _error = e.message;
        _revoking.remove(sessionId);
      });
    }
  }

  Future<void> _revokeAll() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Sign out everywhere?'),
        content: const Text(
            'This will sign you out of all devices, including this one.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('Cancel')),
          TextButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('Sign out all')),
        ],
      ),
    );
    if (confirmed != true) return;

    await ref.read(authStateNotifierProvider.notifier).logout();
    if (mounted) {
      await _api.revokeAllSessions();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Active Sessions'),
        actions: [
          if (_sessions != null && _sessions!.isNotEmpty)
            TextButton(
              onPressed: _revokeAll,
              child: const Text('Sign out all'),
            ),
        ],
      ),
      body: _isLoading
          ? const Center(child: LoadingWidget(message: 'Loading sessions…'))
          : _error != null
              ? _ErrorState(
                  message: _error!,
                  onRetry: _loadSessions,
                )
              : _sessions == null || _sessions!.isEmpty
                  ? const _EmptyState()
                  : RefreshIndicator(
                      onRefresh: _loadSessions,
                      child: ListView.separated(
                        padding: const EdgeInsets.all(16),
                        itemCount: _sessions!.length,
                        separatorBuilder: (_, __) => const Divider(),
                        itemBuilder: (context, i) {
                          final session = _sessions![i];
                          return _SessionTile(
                            session: session,
                            isRevoking: _revoking.contains(session.sessionId),
                            onRevoke: () => _revokeSession(session.sessionId),
                          );
                        },
                      ),
                    ),
    );
  }
}

class _SessionTile extends StatelessWidget {
  const _SessionTile({
    required this.session,
    required this.isRevoking,
    required this.onRevoke,
  });

  final SessionInfo session;
  final bool isRevoking;
  final VoidCallback onRevoke;

  static const _platformIcons = {
    'ios': Icons.phone_iphone,
    'android': Icons.phone_android,
    'web': Icons.computer,
  };

  @override
  Widget build(BuildContext context) {
    final icon = _platformIcons[session.platform?.toLowerCase()] ??
        Icons.devices_other;
    final deviceLabel = session.deviceName ?? session.platform ?? 'Unknown device';
    final lastActive = _formatRelativeTime(session.lastActivityAt);

    return Semantics(
      label: '${session.isCurrent ? "Current session, " : ""}'
          '$deviceLabel, last active $lastActive',
      child: ListTile(
        leading: Icon(icon, semanticLabel: session.platform ?? 'Device'),
        title: Row(
          children: [
            Expanded(child: Text(deviceLabel)),
            if (session.isCurrent)
              Semantics(
                label: 'Current session',
                child: Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(
                    color: Theme.of(context).colorScheme.primaryContainer,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    'This device',
                    style: Theme.of(context).textTheme.labelSmall?.copyWith(
                          color: Theme.of(context)
                              .colorScheme
                              .onPrimaryContainer,
                        ),
                  ),
                ),
              ),
          ],
        ),
        subtitle: Text('Last active: $lastActive'),
        trailing: isRevoking
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : session.isCurrent
                ? null
                : Semantics(
                    button: true,
                    label: 'Revoke session on $deviceLabel',
                    child: IconButton(
                      icon: const Icon(Icons.logout),
                      tooltip: 'Revoke',
                      onPressed: onRevoke,
                    ),
                  ),
      ),
    );
  }

  static String _formatRelativeTime(DateTime time) {
    final diff = DateTime.now().difference(time);
    if (diff.inMinutes < 1) return 'just now';
    if (diff.inHours < 1) return '${diff.inMinutes}m ago';
    if (diff.inDays < 1) return '${diff.inHours}h ago';
    return '${diff.inDays}d ago';
  }
}

class _ErrorState extends StatelessWidget {
  const _ErrorState({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.error_outline,
                size: 48, color: Theme.of(context).colorScheme.error),
            const SizedBox(height: 16),
            Semantics(
              liveRegion: true,
              child: Text(message, textAlign: TextAlign.center),
            ),
            const SizedBox(height: 24),
            AppButton(label: 'Retry', onPressed: onRetry),
          ],
        ),
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Semantics(
        liveRegion: true,
        child: const Text('No active sessions found.'),
      ),
    );
  }
}
