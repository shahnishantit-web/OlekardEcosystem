import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../modules/auth/presentation/auth_providers.dart';
import '../../modules/auth/presentation/screens/splash_screen.dart';
import '../../modules/auth/presentation/screens/login_screen.dart';
import '../../modules/auth/presentation/screens/register_screen.dart';
import '../../modules/auth/presentation/screens/otp_verification_screen.dart';
import '../../modules/auth/presentation/screens/profile_setup_screen.dart';
import '../../modules/auth/presentation/screens/pin_creation_screen.dart';
import '../../modules/auth/presentation/screens/forgot_pin_screen.dart';
import '../../modules/auth/presentation/screens/pin_reset_screen.dart';
import '../../modules/home/presentation/screens/home_screen.dart';
import '../../modules/home/presentation/screens/notifications_screen.dart';
import '../../modules/transactions/presentation/screens/transaction_list_screen.dart';
import '../../modules/transactions/presentation/screens/transaction_detail_screen.dart';
import '../../modules/wallet/presentation/screens/wallet_detail_screen.dart';
import '../../modules/wallet/presentation/screens/receive_money_screen.dart';
import '../../modules/services/presentation/screens/services_home_screen.dart';
import '../../modules/services/presentation/screens/service_providers_screen.dart';
import '../../modules/services/presentation/screens/service_history_screen.dart';
import '../../modules/profile/presentation/screens/profile_screen.dart';
import '../../modules/profile/presentation/screens/edit_profile_screen.dart';
import '../../modules/security_settings/presentation/screens/security_settings_screen.dart';
import '../../modules/security_settings/presentation/screens/change_pin_screen.dart';
import '../../modules/security_settings/presentation/screens/device_management_screen.dart';
import '../../modules/support/presentation/screens/support_home_screen.dart';
import '../../modules/support/presentation/screens/new_inquiry_screen.dart';
import '../../modules/support/presentation/screens/ticket_detail_screen.dart';
import '../../modules/support/presentation/screens/dispute_list_screen.dart';
import '../../modules/support/presentation/screens/raise_dispute_screen.dart';
import '../../modules/support/presentation/screens/dispute_detail_screen.dart';
import '../../modules/payment/presentation/money_flow_screen.dart';
import '../../modules/payment/application/customer_money_service.dart';
import '../../modules/security_settings/presentation/screens/step_up_pin_screen.dart';
import '../shell/main_shell.dart';

final routerProvider = Provider<GoRouter>((ref) {
  // Refresh redirects without replacing the Navigator and its active PIN sheet.
  final authenticated = ValueNotifier(ref.read(authProvider).isAuthenticated);
  ref.listen(authProvider.select((state) => state.isAuthenticated), (_, next) {
    authenticated.value = next;
  });

  final router = GoRouter(
    initialLocation: '/',
    refreshListenable: authenticated,
    redirect: (context, state) {
      final isAuthenticated = ref.read(authProvider).isAuthenticated;
      final isAuthRoute = state.matchedLocation.startsWith('/login') ||
          state.matchedLocation.startsWith('/register') ||
          state.matchedLocation.startsWith('/forgot-pin') ||
          state.matchedLocation == '/';

      if (!isAuthenticated && !isAuthRoute) {
        return '/login';
      }
      if (isAuthenticated && isAuthRoute && state.matchedLocation != '/') {
        return '/home';
      }
      return null;
    },
    routes: [
      GoRoute(path: '/', builder: (_, __) => const SplashScreen()),
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/register', builder: (_, __) => const RegisterScreen()),
      GoRoute(
          path: '/register/otp',
          builder: (_, __) => const OtpVerificationScreen()),
      GoRoute(
          path: '/register/profile',
          builder: (_, __) => const ProfileSetupScreen()),
      GoRoute(
          path: '/register/pin', builder: (_, __) => const PinCreationScreen()),
      GoRoute(path: '/forgot-pin', builder: (_, __) => const ForgotPinScreen()),
      GoRoute(
          path: '/forgot-pin/otp',
          builder: (_, __) => const OtpVerificationScreen()),
      GoRoute(
          path: '/forgot-pin/new-pin',
          builder: (_, __) => const PinResetScreen()),
      GoRoute(
          path: '/step-up-pin', builder: (_, __) => const StepUpPinScreen()),
      // Full-screen financial routes (outside shell)
      GoRoute(
          path: '/add-money',
          builder: (_, __) =>
              const MoneyFlowScreen(kind: MoneyFlowKind.funding)),
      GoRoute(
          path: '/send',
          builder: (_, __) =>
              const MoneyFlowScreen(kind: MoneyFlowKind.transfer)),
      GoRoute(
          path: '/withdraw',
          builder: (_, __) =>
              const MoneyFlowScreen(kind: MoneyFlowKind.withdrawal)),
      // Main shell with bottom nav
      ShellRoute(
        builder: (context, state, child) => MainShell(child: child),
        routes: [
          GoRoute(path: '/home', builder: (_, __) => const HomeScreen()),
          GoRoute(
            path: '/home/notifications',
            builder: (_, __) => const NotificationsScreen(),
          ),
          GoRoute(
            path: '/activity',
            builder: (_, __) => const TransactionListScreen(),
          ),
          GoRoute(
            path: '/activity/:id',
            builder: (context, state) =>
                TransactionDetailScreen(id: state.pathParameters['id']!),
          ),
          GoRoute(
            path: '/home/transactions',
            redirect: (_, __) => '/activity',
          ),
          GoRoute(
            path: '/home/transactions/:id',
            redirect: (context, state) =>
                '/activity/${state.pathParameters['id']!}',
          ),
          GoRoute(
              path: '/wallet', builder: (_, __) => const WalletDetailScreen()),
          GoRoute(
              path: '/receive', builder: (_, __) => const ReceiveMoneyScreen()),
          GoRoute(
              path: '/services',
              builder: (_, __) => const ServicesHomeScreen()),
          GoRoute(
            path: '/services/history',
            builder: (_, __) => const ServiceHistoryScreen(),
          ),
          GoRoute(
            path: '/services/:categoryId',
            builder: (context, state) => ServiceProvidersScreen(
                categoryId: state.pathParameters['categoryId']!),
          ),
          GoRoute(
            path: '/services/purchase/:providerId',
            builder: (context, state) => MoneyFlowScreen(
                kind: MoneyFlowKind.service,
                serviceId: state.pathParameters['providerId']!),
          ),
          GoRoute(path: '/profile', builder: (_, __) => const ProfileScreen()),
          GoRoute(
              path: '/profile/edit',
              builder: (_, __) => const EditProfileScreen()),
          GoRoute(
            path: '/security',
            builder: (_, __) => const SecuritySettingsScreen(),
          ),
          GoRoute(
            path: '/security/change-pin',
            builder: (_, __) => const ChangePinScreen(),
          ),
          GoRoute(
            path: '/security/devices',
            builder: (_, __) => const DeviceManagementScreen(),
          ),
          GoRoute(
              path: '/support', builder: (_, __) => const SupportHomeScreen()),
          GoRoute(
              path: '/support/new',
              builder: (_, __) => const NewInquiryScreen()),
          GoRoute(
            path: '/support/:id',
            builder: (context, state) =>
                TicketDetailScreen(ticketId: state.pathParameters['id']!),
          ),
          GoRoute(
              path: '/disputes', builder: (_, __) => const DisputeListScreen()),
          GoRoute(
            path: '/disputes/new/:txId',
            builder: (_, __) => const RaiseDisputeScreen(),
          ),
          GoRoute(
            path: '/disputes/:id',
            builder: (context, state) =>
                DisputeDetailScreen(disputeId: state.pathParameters['id']!),
          ),
        ],
      ),
    ],
  );
  ref.onDispose(() {
    router.dispose();
    authenticated.dispose();
  });
  return router;
});
