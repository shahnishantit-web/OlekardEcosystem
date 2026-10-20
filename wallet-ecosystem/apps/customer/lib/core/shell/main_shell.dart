import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../shared/widgets/offline_banner.dart';

class MainShell extends StatelessWidget {
  final Widget child;

  const MainShell({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        top: true,
        bottom: false,
        child: Column(
          children: [
            const OfflineBanner(),
            Expanded(child: child),
          ],
        ),
      ),
      bottomNavigationBar: _BottomNav(),
    );
  }
}

class _BottomNav extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final location = GoRouterState.of(context).matchedLocation;
    final index = _indexForLocation(location);
    final reduceMotion = MediaQuery.of(context).disableAnimations;

    return NavigationBar(
      selectedIndex: index,
      onDestinationSelected: (i) => _onTap(context, i),
      animationDuration: reduceMotion ? Duration.zero : const Duration(milliseconds: 200),
      destinations: const [
        NavigationDestination(
          icon: Icon(Icons.home_outlined),
          selectedIcon: Icon(Icons.home),
          label: 'Home',
        ),
        NavigationDestination(
          icon: Icon(Icons.account_balance_wallet_outlined),
          selectedIcon: Icon(Icons.account_balance_wallet),
          label: 'Wallet',
        ),
        NavigationDestination(
          icon: Icon(Icons.grid_view_outlined),
          selectedIcon: Icon(Icons.grid_view),
          label: 'Services',
        ),
        NavigationDestination(
          icon: Icon(Icons.receipt_long_outlined),
          selectedIcon: Icon(Icons.receipt_long),
          label: 'Activity',
        ),
        NavigationDestination(
          icon: Icon(Icons.person_outline),
          selectedIcon: Icon(Icons.person),
          label: 'Account',
        ),
      ],
    );
  }

  int _indexForLocation(String location) {
    if (location.startsWith('/wallet') || location.startsWith('/receive')) { return 1; }
    if (location.startsWith('/services')) { return 2; }
    if (location.startsWith('/activity')) { return 3; }
    if (location.startsWith('/profile') ||
        location.startsWith('/security') ||
        location.startsWith('/support') ||
        location.startsWith('/disputes')) { return 4; }
    return 0;
  }

  void _onTap(BuildContext context, int index) {
    switch (index) {
      case 0:
        context.go('/home');
      case 1:
        context.go('/wallet');
      case 2:
        context.go('/services');
      case 3:
        context.go('/activity');
      case 4:
        context.go('/profile');
    }
  }
}
