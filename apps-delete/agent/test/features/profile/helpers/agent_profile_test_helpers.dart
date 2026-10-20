import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:agent/features/profile/models/agent_profile_models.dart';
import 'package:agent/features/profile/state/agent_profile_state.dart';
import 'package:agent/features/profile/providers/agent_profile_state_notifier.dart';

// ---------------------------------------------------------------------------
// Shared fake data
// ---------------------------------------------------------------------------

const fakeProfile = AgentProfileData(
  userId: 'agent-001',
  firstName: 'Grace',
  lastName: 'Hopper',
  mobileNumber: '+26771234567',
  accountStatus: 'active',
  profilePhotoUrl: 'https://example.com/photo.jpg',
);

const fakeProfileNoPhoto = AgentProfileData(
  userId: 'agent-001',
  firstName: 'Grace',
  lastName: 'Hopper',
  mobileNumber: '+26771234567',
  accountStatus: 'active',
);

AgentAccountStatusData fakeAccountStatus() => AgentAccountStatusData(
      status: 'active',
      history: [
        AgentStatusHistoryEntry(
          fromStatus: 'pending',
          toStatus: 'active',
          actorType: 'system',
          createdAt: DateTime(2024, 1, 1),
        ),
      ],
    );

AgentContactChangeChallenge fakeMobileChallenge() =>
    AgentContactChangeChallenge(
      newContact: '+26777654321',
      challengeId: 'ch-001',
      expiresAt: DateTime.now().add(const Duration(minutes: 10)),
      maskedDestination: '+267*****321',
    );

// ---------------------------------------------------------------------------
// Stub notifiers
// ---------------------------------------------------------------------------

class LoadedNotifier extends AgentProfileStateNotifier {
  @override
  Future<AgentProfileState> build() async =>
      const AgentProfileLoaded(profile: fakeProfile);
}

class AlwaysLoadingNotifier extends AgentProfileStateNotifier {
  @override
  Future<AgentProfileState> build() => Completer<AgentProfileState>().future;
}

class ErrorNotifier extends AgentProfileStateNotifier {
  @override
  Future<AgentProfileState> build() async =>
      const AgentProfileError(message: 'Something went wrong.', code: 'NET_ERR');
}

class StatefulNotifier extends AgentProfileStateNotifier {
  StatefulNotifier(this._state);
  final AgentProfileState _state;

  @override
  Future<AgentProfileState> build() async => _state;
}

// ---------------------------------------------------------------------------
// Widget wrapper
// ---------------------------------------------------------------------------

/// Wraps [child] in a [ProviderScope] + [MaterialApp] with profile providers
/// overridden. [extraRoutes] registers named route stubs for push tests.
Widget wrapAgentProfile(
  Widget child, {
  AgentProfileStateNotifier? notifier,
  Map<String, WidgetBuilder>? extraRoutes,
}) {
  return ProviderScope(
    overrides: [
      agentProfileStateNotifierProvider.overrideWith(
        notifier != null ? () => notifier : LoadedNotifier.new,
      ),
      agentProfileApiServiceProvider.overrideWith(
        (_) => throw UnimplementedError('Not used in widget tests'),
      ),
    ],
    child: MaterialApp(
      home: child,
      routes: {
        '/profile/mobile/verify': (_) =>
            const Scaffold(body: Text('MobileVerify')),
        ...?extraRoutes,
      },
    ),
  );
}
