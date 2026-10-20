import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

class AgentShell extends StatelessWidget {
  final Widget child;

  const AgentShell({super.key, required this.child});

  static const _destinations = [
    NavigationDestination(
      icon: Icon(Icons.dashboard_outlined),
      selectedIcon: Icon(Icons.dashboard),
      label: 'Dashboard',
    ),
    NavigationDestination(
      icon: Icon(Icons.people_outline),
      selectedIcon: Icon(Icons.people),
      label: 'Customers',
    ),
    NavigationDestination(
      icon: Icon(Icons.receipt_long_outlined),
      selectedIcon: Icon(Icons.receipt_long),
      label: 'Transactions',
    ),
    NavigationDestination(
      icon: Icon(Icons.notifications_outlined),
      selectedIcon: Icon(Icons.notifications),
      label: 'Activity',
    ),
    NavigationDestination(
      icon: Icon(Icons.person_outline),
      selectedIcon: Icon(Icons.person),
      label: 'Account',
    ),
  ];

  static int _indexForLocation(String loc) {
    if (loc.startsWith('/customers')) { return 1; }
    if (loc.startsWith('/transactions')) { return 2; }
    if (loc.startsWith('/notifications')) { return 3; }
    if (loc.startsWith('/profile') ||
        loc.startsWith('/change-pin') ||
        loc.startsWith('/security')) { return 4; }
    return 0; // /dashboard
  }

  static void _onTap(BuildContext context, int index) {
    switch (index) {
      case 0:
        context.go('/dashboard');
      case 1:
        context.go('/customers');
      case 2:
        context.go('/transactions');
      case 3:
        context.go('/notifications');
      case 4:
        context.go('/profile');
    }
  }

  @override
  Widget build(BuildContext context) {
    final loc = GoRouterState.of(context).matchedLocation;
    final reduceMotion = MediaQuery.of(context).disableAnimations;

    return Scaffold(
      body: SafeArea(
        top: true,
        bottom: false,
        child: child,
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _indexForLocation(loc),
        onDestinationSelected: (i) => _onTap(context, i),
        animationDuration: reduceMotion ? Duration.zero : const Duration(milliseconds: 200),
        destinations: _destinations,
      ),
    );
  }
}
