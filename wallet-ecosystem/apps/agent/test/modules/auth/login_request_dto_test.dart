import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_agent/modules/auth/data/models/login_request_dto.dart';

void main() {
  test('login serializes the entered PIN for server-side verification', () {
    expect(
      const LoginRequestDto(phone: '+26771000001', pin: '123456').toJson(),
      {'phone': '+26771000001', 'pin': '123456'},
    );
  });
}
