import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/modules/auth/data/repositories/auth_repository_impl.dart';

void main() {
  test('auth errors expose their message to the login screen', () {
    expect(const AuthException('Invalid credentials.').toString(),
        'Invalid credentials.');
  });
}
