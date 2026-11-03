import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:wallet_agent/modules/auth/data/models/auth_response_dto.dart';
import 'package:wallet_agent/modules/auth/domain/entities/agent.dart';
import 'package:wallet_agent/modules/auth/domain/entities/auth_token.dart';
import 'package:wallet_agent/modules/auth/domain/repositories/auth_repository.dart';
import 'package:wallet_agent/modules/auth/presentation/auth_providers.dart';
import 'package:wallet_agent/core/security/secure_storage_service.dart';

class MockAuthRepository extends Mock implements AuthRepository {}

class MockSecureStorageService extends Mock implements SecureStorageService {}

void main() {
  late MockAuthRepository mockRepository;
  late MockSecureStorageService mockStorage;

  setUp(() {
    mockRepository = MockAuthRepository();
    mockStorage = MockSecureStorageService();
    when(() => mockStorage.getAccessToken()).thenAnswer((_) async => null);
    when(() => mockStorage.getRefreshToken()).thenAnswer((_) async => null);
  });

  group('AuthState transitions', () {
    test(
        'starts in unknown state and transitions to unauthenticated when no token',
        () async {
      final notifier = AuthNotifier(
        repository: mockRepository,
        storage: mockStorage,
      );

      // Wait for async initialize
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(notifier.state.status, AuthStateEnum.unauthenticated);
    });

    test('login() transitions to authenticated with agent data', () async {
      final fixtureJson = json.decode(
        File('test/fixtures/agent.json').readAsStringSync(),
      ) as Map<String, dynamic>;

      final dto = AuthResponseDto.fromJson(fixtureJson);
      final agent = dto.agent.toEntity();
      final token = dto.toAuthToken();

      when(() => mockStorage.saveAccessToken(any())).thenAnswer((_) async {});
      when(() => mockStorage.saveRefreshToken(any())).thenAnswer((_) async {});
      when(
        () => mockRepository.login(
            phone: any(named: 'phone'), pin: any(named: 'pin')),
      ).thenAnswer((_) async => (token: token, agent: agent));

      final notifier = AuthNotifier(
        repository: mockRepository,
        storage: mockStorage,
      );
      await Future<void>.delayed(const Duration(milliseconds: 50));

      await notifier.login(phone: '+26771000001', pin: '123456');

      expect(notifier.state.status, AuthStateEnum.authenticated);
      expect(notifier.state.agent?.phone, '+26771000001');
      expect(notifier.state.agent?.fullName, 'Sipho Dlamini');
    });

    test('late session initialization cannot undo a successful login',
        () async {
      final accessTokenRead = Completer<String?>();
      when(() => mockStorage.getAccessToken())
          .thenAnswer((_) => accessTokenRead.future);
      final fixtureJson = json.decode(
        File('test/fixtures/agent.json').readAsStringSync(),
      ) as Map<String, dynamic>;
      final dto = AuthResponseDto.fromJson(fixtureJson);
      final token = dto.toAuthToken();
      final agent = dto.agent.toEntity();
      when(() => mockStorage.saveAccessToken(any())).thenAnswer((_) async {});
      when(() => mockStorage.saveRefreshToken(any())).thenAnswer((_) async {});
      when(
        () => mockRepository.login(
          phone: any(named: 'phone'),
          pin: any(named: 'pin'),
        ),
      ).thenAnswer((_) async => (token: token, agent: agent));

      final notifier = AuthNotifier(
        repository: mockRepository,
        storage: mockStorage,
      );
      await notifier.login(phone: '+26771000001', pin: '123456');
      accessTokenRead.complete(null);
      await Future<void>.delayed(Duration.zero);

      expect(notifier.state.status, AuthStateEnum.authenticated);
      expect(notifier.state.agent?.id, agent.id);
    });

    test('requireReauth() transitions authenticated state to requiresReauth',
        () async {
      final notifier = AuthNotifier(
        repository: mockRepository,
        storage: mockStorage,
      );
      await Future<void>.delayed(const Duration(milliseconds: 50));

      // Manually put in authenticated state
      notifier.requireReauth();
      expect(notifier.state.status, AuthStateEnum.requiresReauth);
    });

    test('logout() transitions to unauthenticated', () async {
      when(() => mockStorage.clearAuthTokens()).thenAnswer((_) async {});
      when(() => mockRepository.logout()).thenAnswer((_) async {});

      final notifier = AuthNotifier(
        repository: mockRepository,
        storage: mockStorage,
      );
      await Future<void>.delayed(const Duration(milliseconds: 50));

      await notifier.logout();
      expect(notifier.state.status, AuthStateEnum.unauthenticated);
    });
  });

  group('AuthToken', () {
    test('isExpired returns true for past expiry', () {
      final token = AuthToken(
        accessToken: 'tok',
        refreshToken: 'ref',
        expiresAt: DateTime.now().subtract(const Duration(seconds: 1)),
      );
      expect(token.isExpired, isTrue);
    });

    test('isExpired returns false for future expiry', () {
      final token = AuthToken(
        accessToken: 'tok',
        refreshToken: 'ref',
        expiresAt: DateTime.now().add(const Duration(hours: 1)),
      );
      expect(token.isExpired, isFalse);
    });
  });

  group('AuthResponseDto.fromJson', () {
    test('parses agent fixture correctly', () {
      final fixtureJson = json.decode(
        File('test/fixtures/agent.json').readAsStringSync(),
      ) as Map<String, dynamic>;

      final dto = AuthResponseDto.fromJson(fixtureJson);
      final agent = dto.agent.toEntity();

      expect(agent.id, '01931234-0000-7000-8000-000000000001');
      expect(agent.status, AgentStatus.active);
      expect(agent.kycStatus, KycStatus.verified);
      expect(agent.fullName, 'Sipho Dlamini');
      expect(agent.locationCode, 'GAB-001');
    });
  });
}
