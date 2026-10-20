import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../modules/auth/screens/device/device_verify_screen.dart';
import '../modules/auth/screens/locked/account_locked_screen.dart';
import '../modules/auth/screens/otp/otp_verify_screen.dart';
import '../modules/auth/screens/pin/pin_change_screen.dart';
import '../modules/auth/screens/pin/pin_create_screen.dart';
import '../modules/auth/screens/pin/pin_entry_screen.dart';
import '../modules/auth/screens/pin/pin_reset_screen.dart';
import '../modules/auth/screens/pin/pin_unblock_screen.dart';
import '../modules/auth/screens/registration/registration_screen.dart';
import '../modules/auth/screens/sessions/sessions_screen.dart';
import '../modules/auth/state/auth_state.dart';
import '../modules/auth/state/auth_state_notifier.dart';
import '../shared/widgets/loading.dart';
import '../features/profile/screens/agent_profile_view_screen.dart';
import '../features/profile/screens/agent_profile_edit_screen.dart';
import '../features/profile/screens/agent_photo_screen.dart';
import '../features/profile/screens/agent_mobile_change_screen.dart';
import '../features/profile/screens/agent_mobile_verify_screen.dart';
import '../features/profile/screens/agent_status_screen.dart';

final routerProvider = Provider<GoRouter>((ref) {
  final notifier = _RouterNotifier(ref);

  return GoRouter(
    refreshListenable: notifier,
    redirect: notifier.redirect,
    initialLocation: '/auth/register',
    routes: [
      // ── Auth routes ─────────────────────────────────────────────────────
      GoRoute(
        path: '/auth/register',
        builder: (_, __) => const RegistrationScreen(),
      ),
      GoRoute(
        path: '/auth/login',
        builder: (_, state) => PinEntryScreen(
          identifier: state.uri.queryParameters['identifier'] ?? '',
        ),
      ),
      GoRoute(
        path: '/auth/otp',
        builder: (_, __) => const OtpVerifyScreen(),
      ),
      GoRoute(
        path: '/auth/pin/set',
        builder: (_, __) => const PinCreateScreen(),
      ),
      GoRoute(
        path: '/auth/pin/change',
        builder: (_, __) => const PinChangeScreen(),
      ),
      GoRoute(
        path: '/auth/pin/reset',
        builder: (_, __) => const PinResetScreen(),
      ),
      GoRoute(
        path: '/auth/pin/unblock',
        builder: (_, __) => const PinUnblockScreen(),
      ),
      GoRoute(
        path: '/auth/locked',
        builder: (_, __) => const AccountLockedScreen(),
      ),
      GoRoute(
        path: '/auth/device/verify',
        builder: (_, __) => const DeviceVerifyScreen(),
      ),
      GoRoute(
        path: '/auth/sessions',
        builder: (_, __) => const SessionsScreen(),
      ),

      // ── Authenticated routes ─────────────────────────────────────────────
      GoRoute(
        path: '/dashboard',
        builder: (_, __) => const _DashboardStub(),
      ),

      // ── Profile routes (Phase 16) ────────────────────────────────────────
      GoRoute(
        path: '/profile',
        builder: (_, __) => const AgentProfileViewScreen(),
      ),
      GoRoute(
        path: '/profile/edit',
        builder: (_, __) => const AgentProfileEditScreen(),
      ),
      GoRoute(
        path: '/profile/photo',
        builder: (_, __) => const AgentPhotoScreen(),
      ),
      GoRoute(
        path: '/profile/mobile',
        builder: (_, __) => const AgentMobileChangeScreen(),
      ),
      GoRoute(
        path: '/profile/mobile/verify',
        builder: (_, __) => const AgentMobileVerifyScreen(),
      ),
      GoRoute(
        path: '/profile/status',
        builder: (_, __) => const AgentStatusScreen(),
      ),
    ],
    errorBuilder: (_, state) => Scaffold(
      body: Center(child: Text('Page not found: ${state.error}')),
    ),
  );
});

/// Riverpod-aware ChangeNotifier that triggers go_router re-evaluation
/// whenever the auth state changes.
class _RouterNotifier extends ChangeNotifier {
  _RouterNotifier(this._ref) {
    _ref.listen<AsyncValue<AuthState>>(
      authStateNotifierProvider,
      (_, __) => notifyListeners(),
    );
  }

  final Ref _ref;

  String? redirect(BuildContext context, GoRouterState state) {
    final authValue = _ref.read(authStateNotifierProvider);

    if (authValue.isLoading) return null;

    final authState = authValue.valueOrNull;
    final loc = state.matchedLocation;

    return switch (authState) {
      null => null,
      AuthAuthenticated() => _isAuthOnlyRoute(loc) ? '/dashboard' : null,
      AuthUnauthenticated() =>
        _isProtectedRoute(loc) ? '/auth/register' : null,
      AuthOtpPending() => loc != '/auth/otp' ? '/auth/otp' : null,
      AuthPinPending() => loc != '/auth/pin/set' ? '/auth/pin/set' : null,
      AuthLocked() =>
        (loc != '/auth/locked' && loc != '/auth/pin/unblock')
            ? '/auth/locked'
            : null,
      AuthPinUnblockPending() =>
        loc != '/auth/pin/unblock' ? '/auth/pin/unblock' : null,
      AuthDeviceVerificationRequired() =>
        loc != '/auth/device/verify' ? '/auth/device/verify' : null,
    };
  }

  static bool _isAuthOnlyRoute(String loc) =>
      loc.startsWith('/auth/register') ||
      loc.startsWith('/auth/login') ||
      loc.startsWith('/auth/otp');

  static bool _isProtectedRoute(String loc) =>
      loc.startsWith('/dashboard') ||
      loc.startsWith('/profile') ||
      loc.startsWith('/auth/pin/change') ||
      loc.startsWith('/auth/sessions');
}

class _DashboardStub extends StatelessWidget {
  const _DashboardStub();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Agent Dashboard')),
      body: const Center(child: LoadingWidget(message: 'Loading…')),
    );
  }
}
