import 'package:flutter_riverpod/flutter_riverpod.dart';

class AuthState {
  const AuthState({this.user, this.isAuthenticated = false});

  final Object? user;
  final bool isAuthenticated;
}

class AuthNotifier extends AsyncNotifier<AuthState> {
  @override
  Future<AuthState> build() async {
    // Stub — always unauthenticated in Platform Foundation.
    // E31/E32 implement real token-based auth.
    return const AuthState(isAuthenticated: false);
  }
}

final authProvider = AsyncNotifierProvider<AuthNotifier, AuthState>(
  AuthNotifier.new,
);
