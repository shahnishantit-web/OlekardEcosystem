import 'package:flutter_riverpod/flutter_riverpod.dart';

class AuthState {
  final dynamic user;
  final bool isAuthenticated;

  const AuthState({this.user, this.isAuthenticated = false});
}

class AuthNotifier extends AsyncNotifier<AuthState> {
  @override
  Future<AuthState> build() async {
    // Stub: always unauthenticated. E31/E32 implement real auth logic.
    return const AuthState(user: null, isAuthenticated: false);
  }
}

final authProvider = AsyncNotifierProvider<AuthNotifier, AuthState>(
  AuthNotifier.new,
);
