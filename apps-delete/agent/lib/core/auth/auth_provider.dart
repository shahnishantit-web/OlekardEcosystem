import 'package:flutter_riverpod/flutter_riverpod.dart';

class AuthState {
  const AuthState({this.isAuthenticated = false});

  final bool isAuthenticated;
}

// Stub: always unauthenticated. Replaced by Identity module (E02).
class AuthNotifier extends AsyncNotifier<AuthState> {
  @override
  Future<AuthState> build() async {
    return const AuthState(isAuthenticated: false);
  }
}

final authProvider = AsyncNotifierProvider<AuthNotifier, AuthState>(
  AuthNotifier.new,
);
