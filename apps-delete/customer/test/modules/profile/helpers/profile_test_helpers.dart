import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:customer/modules/profile/models/profile_models.dart';
import 'package:customer/modules/profile/services/profile_api_service.dart';
import 'package:customer/modules/profile/state/profile_state.dart';
import 'package:customer/modules/profile/state/profile_state_notifier.dart';

// ---------------------------------------------------------------------------
// Shared fake data factories
// ---------------------------------------------------------------------------

const fakeProfile = ProfileData(
  userId: 'user-001',
  firstName: 'Ada',
  lastName: 'Lovelace',
  email: 'ada@example.com',
  mobileNumber: '+26771234567',
  accountStatus: 'active',
  profilePhotoUrl: 'https://example.com/photo.jpg',
);

const fakeProfileNoPhoto = ProfileData(
  userId: 'user-001',
  firstName: 'Ada',
  lastName: 'Lovelace',
  email: 'ada@example.com',
  mobileNumber: '+26771234567',
  accountStatus: 'active',
);

AccountStatusData fakeAccountStatus() => AccountStatusData(
      status: 'active',
      history: [
        StatusHistoryEntry(
          fromStatus: 'pending',
          toStatus: 'active',
          actorType: 'system',
          createdAt: DateTime(2024, 1, 1),
        ),
      ],
    );

ContactChangeChallenge fakeEmailChallenge() => ContactChangeChallenge(
      type: 'email',
      newContact: 'new@example.com',
      challengeId: 'ch-001',
      expiresAt: DateTime.now().add(const Duration(minutes: 10)),
      maskedDestination: 'n**@example.com',
    );

ContactChangeChallenge fakeMobileChallenge() => ContactChangeChallenge(
      type: 'mobile',
      newContact: '+26777654321',
      challengeId: 'ch-002',
      expiresAt: DateTime.now().add(const Duration(minutes: 10)),
      maskedDestination: '+267*****321',
    );

DataExportData fakePendingExport() => DataExportData(
      exportId: 'exp-001',
      status: 'pending',
      requestedAt: DateTime.now(),
    );

// ---------------------------------------------------------------------------
// Stub notifiers
// ---------------------------------------------------------------------------

/// Resolves immediately with [ProfileLoaded] using [fakeProfile].
class LoadedNotifier extends ProfileStateNotifier {
  @override
  Future<ProfileState> build() async =>
      const ProfileLoaded(profile: fakeProfile);
}

/// Never completes — keeps state in AsyncLoading.
class AlwaysLoadingNotifier extends ProfileStateNotifier {
  @override
  Future<ProfileState> build() => Completer<ProfileState>().future;
}

/// Returns [ProfileError].
class ErrorNotifier extends ProfileStateNotifier {
  @override
  Future<ProfileState> build() async =>
      const ProfileError(message: 'Something went wrong.', code: 'NET_ERR');
}

/// Returns a caller-supplied [ProfileState].
class StatefulNotifier extends ProfileStateNotifier {
  StatefulNotifier(this._state);
  final ProfileState _state;

  @override
  Future<ProfileState> build() async => _state;
}

// ---------------------------------------------------------------------------
// Widget wrapper
// ---------------------------------------------------------------------------

/// Wraps [child] in a [ProviderScope] + [MaterialApp] with all profile
/// providers overridden.  Pass [notifier] for a custom state; defaults to
/// [LoadedNotifier].  [extraRoutes] registers named stubs for push tests.
Widget wrapProfile(
  Widget child, {
  ProfileStateNotifier? notifier,
  Map<String, WidgetBuilder>? extraRoutes,
}) {
  return ProviderScope(
    overrides: [
      profileStateNotifierProvider.overrideWith(
        notifier != null ? () => notifier : LoadedNotifier.new,
      ),
      profileApiServiceProvider.overrideWith(
        (_) => throw UnimplementedError('Not used in widget tests'),
      ),
    ],
    child: MaterialApp(
      home: child,
      routes: {
        '/profile/email/verify': (_) =>
            const Scaffold(body: Text('EmailVerify')),
        '/profile/mobile/verify': (_) =>
            const Scaffold(body: Text('MobileVerify')),
        ...?extraRoutes,
      },
    ),
  );
}
