import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/modules/auth/presentation/auth_providers.dart';
import 'package:wallet_agent/modules/auth/presentation/screens/forgot_pin_screen.dart';
import 'package:wallet_agent/modules/auth/presentation/screens/login_screen.dart';
import 'package:wallet_agent/modules/auth/presentation/screens/otp_verification_screen.dart';
import 'package:wallet_agent/modules/auth/presentation/screens/pin_reset_screen.dart';
import 'package:wallet_agent/modules/auth/presentation/screens/splash_screen.dart';
import 'package:wallet_agent/modules/auth/presentation/screens/step_up_pin_screen.dart';
import 'package:wallet_agent/modules/cash_in/domain/entities/cash_in_result.dart';
import 'package:wallet_agent/modules/cash_in/presentation/screens/cash_in_confirmation_screen.dart';
import 'package:wallet_agent/modules/cash_in/presentation/screens/cash_in_form_screen.dart';
import 'package:wallet_agent/modules/cash_in/presentation/screens/cash_in_result_screen.dart';
import 'package:wallet_agent/modules/cash_in/presentation/screens/customer_search_screen.dart';
import 'package:wallet_agent/modules/cash_out/domain/entities/cash_out_result.dart';
import 'package:wallet_agent/modules/cash_out/presentation/screens/cash_out_confirmation_screen.dart';
import 'package:wallet_agent/modules/cash_out/presentation/screens/cash_out_customer_search_screen.dart';
import 'package:wallet_agent/modules/cash_out/presentation/screens/cash_out_form_screen.dart';
import 'package:wallet_agent/modules/cash_out/presentation/screens/cash_out_result_screen.dart';
import 'package:wallet_agent/modules/cash_out/presentation/screens/pickup_code_screen.dart';
import 'package:wallet_agent/modules/commissions/presentation/screens/commissions_screen.dart';
import 'package:wallet_agent/modules/customer_onboarding/domain/entities/customer_profile.dart';
import 'package:wallet_agent/modules/customer_onboarding/presentation/screens/customer_lookup_screen.dart';
import 'package:wallet_agent/modules/customer_onboarding/presentation/screens/customer_registration_form_screen.dart';
import 'package:wallet_agent/modules/customer_onboarding/presentation/screens/customer_registration_result_screen.dart';
import 'package:wallet_agent/modules/customer_onboarding/presentation/screens/customer_status_screen.dart';
import 'package:wallet_agent/modules/customer_onboarding/presentation/screens/kyc_capture_screen.dart';
import 'package:wallet_agent/modules/dashboard/presentation/screens/dashboard_screen.dart';
import 'package:wallet_agent/modules/float/presentation/screens/float_screen.dart';
import 'package:wallet_agent/modules/float/presentation/screens/float_top_up_screen.dart';
import 'package:wallet_agent/modules/notifications/presentation/screens/notifications_screen.dart';
import 'package:wallet_agent/modules/profile/presentation/screens/change_pin_screen.dart';
import 'package:wallet_agent/modules/profile/presentation/screens/profile_screen.dart';
import 'package:wallet_agent/modules/profile/presentation/screens/security_settings_screen.dart';
import 'package:wallet_agent/modules/transactions/presentation/screens/transaction_detail_screen.dart';
import 'package:wallet_agent/modules/transactions/presentation/screens/transaction_list_screen.dart';

final _rootNavigatorKey = GlobalKey<NavigatorState>(debugLabel: 'root');
final _shellNavigatorKey = GlobalKey<NavigatorState>(debugLabel: 'shell');

final routerProvider = Provider<GoRouter>((ref) {
  final authState = ref.watch(authNotifierProvider);

  return GoRouter(
    navigatorKey: _rootNavigatorKey,
    initialLocation: '/',
    redirect: (context, state) {
      final status = authState.status;
      final loc = state.matchedLocation;
      final isOnAuthRoute = loc == '/login' ||
          loc.startsWith('/forgot-pin') ||
          loc == '/';

      if (status == AuthStateEnum.unauthenticated && !isOnAuthRoute) {
        return '/login';
      }
      if (status == AuthStateEnum.requiresReauth && !isOnAuthRoute) {
        return '/login';
      }
      if (status == AuthStateEnum.authenticated &&
          (loc == '/login' || loc == '/')) {
        return '/dashboard';
      }
      return null;
    },
    routes: [
      GoRoute(path: '/', builder: (_, __) => const SplashScreen()),
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(
          path: '/forgot-pin', builder: (_, __) => const ForgotPinScreen()),
      GoRoute(
        path: '/forgot-pin/otp',
        builder: (_, state) {
          final extra = state.extra as Map<String, dynamic>;
          return OtpVerificationScreen(
            otpReference: extra['otp_reference'] as String,
            phone: extra['phone'] as String,
          );
        },
      ),
      GoRoute(
        path: '/forgot-pin/new-pin',
        builder: (_, state) {
          final extra = state.extra as Map<String, dynamic>;
          return PinResetScreen(
            otpReference: extra['otp_reference'] as String,
            otpCode: extra['otp_code'] as String,
          );
        },
      ),
      GoRoute(
        path: '/step-up-pin',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, __) => const StepUpPinScreen(),
      ),

      // ── Cash-In flow ──────────────────────────────────────────────────
      GoRoute(
        path: '/cash-in/search',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, __) => const CustomerSearchScreen(),
      ),
      GoRoute(
        path: '/cash-in/form',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, state) {
          final e = state.extra as Map<String, dynamic>;
          return CashInFormScreen(
            walletId: e['wallet_id'] as String,
            customerName: e['customer_name'] as String,
            maskedWalletNumber: e['masked_wallet_number'] as String,
          );
        },
      ),
      GoRoute(
        path: '/cash-in/confirm',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, state) {
          final e = state.extra as Map<String, dynamic>;
          return CashInConfirmationScreen(
            walletId: e['wallet_id'] as String,
            customerName: e['customer_name'] as String,
            amountMinorUnits: e['amount_minor_units'] as int,
            currencyCode: e['currency_code'] as String,
          );
        },
      ),
      GoRoute(
        path: '/cash-in/result',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, state) =>
            CashInResultScreen(result: state.extra as CashInResult),
      ),

      // ── Cash-Out flow ─────────────────────────────────────────────────
      // Primary entry: pickup code / QR scan (spec: US-AG-004)
      GoRoute(
        path: '/cash-out/scan',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, __) => const PickupCodeScreen(),
      ),
      // Alternative entry: customer search (retained for manual lookup)
      GoRoute(
        path: '/cash-out/search',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, __) => const CashOutCustomerSearchScreen(),
      ),
      GoRoute(
        path: '/cash-out/form',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, state) {
          final e = state.extra as Map<String, dynamic>;
          return CashOutFormScreen(
            walletId: e['wallet_id'] as String,
            customerName: e['customer_name'] as String,
            maskedWalletNumber: e['masked_wallet_number'] as String,
          );
        },
      ),
      GoRoute(
        path: '/cash-out/confirm',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, state) {
          final e = state.extra as Map<String, dynamic>;
          return CashOutConfirmationScreen(
            walletId: e['wallet_id'] as String,
            customerName: e['customer_name'] as String,
            amountMinorUnits: e['amount_minor_units'] as int,
            currencyCode: e['currency_code'] as String,
          );
        },
      ),
      GoRoute(
        path: '/cash-out/result',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, state) =>
            CashOutResultScreen(result: state.extra as CashOutResult),
      ),

      // ── Customer Onboarding flow ──────────────────────────────────────
      GoRoute(
        path: '/onboarding/register',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, __) => const CustomerRegistrationFormScreen(),
      ),
      GoRoute(
        path: '/onboarding/result',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, state) => CustomerRegistrationResultScreen(
          result: state.extra as RegistrationResult,
        ),
      ),

      // ── Customer Lookup & Status ──────────────────────────────────────
      GoRoute(
        path: '/customers',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, __) => const CustomerLookupScreen(),
      ),
      GoRoute(
        path: '/customers/:id/status',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, state) => CustomerStatusScreen(
          customerId: state.pathParameters['id']!,
        ),
      ),
      GoRoute(
        path: '/customers/:id/kyc',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, state) {
          final extra = state.extra as Map<String, dynamic>? ?? {};
          return KycCaptureScreen(
            customerId: state.pathParameters['id']!,
            customerName: extra['customer_name'] as String? ?? 'Customer',
          );
        },
      ),

      // ── Float Top-Up (full-screen) ────────────────────────────────────
      GoRoute(
        path: '/float/top-up',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, __) => const FloatTopUpScreen(),
      ),

      // ── Security ─────────────────────────────────────────────────────
      GoRoute(
        path: '/security',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, __) => const SecuritySettingsScreen(),
      ),
      GoRoute(
        path: '/change-pin',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (_, __) => const ChangePinScreen(),
      ),

      // ── Shell: 4-tab bottom nav ───────────────────────────────────────
      ShellRoute(
        navigatorKey: _shellNavigatorKey,
        builder: (_, __, child) => ScaffoldWithBottomNav(child: child),
        routes: [
          GoRoute(
            path: '/dashboard',
            builder: (_, __) => const DashboardScreen(),
          ),
          GoRoute(
            path: '/transactions',
            builder: (_, __) => const TransactionListScreen(),
            routes: [
              GoRoute(
                path: ':id',
                builder: (_, state) => TransactionDetailScreen(
                  transactionId: state.pathParameters['id']!,
                ),
              ),
            ],
          ),
          GoRoute(
            path: '/float',
            builder: (_, __) => const FloatScreen(),
          ),
          GoRoute(
            path: '/commissions',
            builder: (_, __) => const CommissionsScreen(),
          ),
          GoRoute(
            path: '/profile',
            builder: (_, __) => const ProfileScreen(),
          ),
          GoRoute(
            path: '/notifications',
            builder: (_, __) => const NotificationsScreen(),
          ),
        ],
      ),
    ],
  );
});

class ScaffoldWithBottomNav extends StatelessWidget {
  final Widget child;
  const ScaffoldWithBottomNav({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    final loc = GoRouterState.of(context).matchedLocation;

    int currentIndex = 0;
    if (loc.startsWith('/transactions')) currentIndex = 1;
    if (loc.startsWith('/float') || loc.startsWith('/commissions')) {
      currentIndex = 2;
    }
    if (loc.startsWith('/profile') || loc.startsWith('/notifications')) {
      currentIndex = 3;
    }

    return Scaffold(
      body: child,
      bottomNavigationBar: NavigationBar(
        selectedIndex: currentIndex,
        onDestinationSelected: (i) {
          switch (i) {
            case 0:
              context.go('/dashboard');
            case 1:
              context.go('/transactions');
            case 2:
              context.go('/float');
            case 3:
              context.go('/profile');
          }
        },
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.dashboard_outlined),
            selectedIcon: Icon(Icons.dashboard),
            label: 'Dashboard',
          ),
          NavigationDestination(
            icon: Icon(Icons.receipt_long_outlined),
            selectedIcon: Icon(Icons.receipt_long),
            label: 'Transactions',
          ),
          NavigationDestination(
            icon: Icon(Icons.account_balance_wallet_outlined),
            selectedIcon: Icon(Icons.account_balance_wallet),
            label: 'Float',
          ),
          NavigationDestination(
            icon: Icon(Icons.person_outline),
            selectedIcon: Icon(Icons.person),
            label: 'Profile',
          ),
        ],
      ),
    );
  }
}
