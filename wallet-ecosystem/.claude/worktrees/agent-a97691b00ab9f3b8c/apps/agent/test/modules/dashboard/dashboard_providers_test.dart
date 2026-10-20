import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:wallet_agent/modules/dashboard/data/models/dashboard_dto.dart';
import 'package:wallet_agent/modules/dashboard/domain/entities/dashboard_summary.dart';
import 'package:wallet_agent/modules/dashboard/domain/repositories/dashboard_repository.dart';

class MockDashboardRepository extends Mock implements DashboardRepository {}

void main() {
  late MockDashboardRepository mockRepository;

  setUp(() {
    mockRepository = MockDashboardRepository();
  });

  group('DashboardDto.fromJson', () {
    test('parses fixture correctly', () {
      final json = jsonDecode(
        File('test/fixtures/dashboard.json').readAsStringSync(),
      ) as Map<String, dynamic>;

      final dto = DashboardDto.fromJson(json);
      final entity = dto.toEntity();

      expect(entity.floatBalanceMinorUnits, 5000000);
      expect(entity.floatCurrencyCode, 'BWP');
      expect(entity.lowFloatThresholdMinorUnits, 500000);
      expect(entity.todayCashInCount, 12);
      expect(entity.todayCashOutCount, 8);
      expect(entity.isLow, isFalse);
    });

    test('isLow returns true when balance <= threshold', () {
      final summary = DashboardSummary(
        floatBalanceMinorUnits: 400000,
        floatCurrencyCode: 'BWP',
        lowFloatThresholdMinorUnits: 500000,
        todayCashInCount: 0,
        todayCashInAmountMinorUnits: 0,
        todayCashOutCount: 0,
        todayCashOutAmountMinorUnits: 0,
        updatedAt: DateTime.now(),
      );
      expect(summary.isLow, isTrue);
    });

    test('isLow returns false when balance > threshold', () {
      final summary = DashboardSummary(
        floatBalanceMinorUnits: 5000000,
        floatCurrencyCode: 'BWP',
        lowFloatThresholdMinorUnits: 500000,
        todayCashInCount: 0,
        todayCashInAmountMinorUnits: 0,
        todayCashOutCount: 0,
        todayCashOutAmountMinorUnits: 0,
        updatedAt: DateTime.now(),
      );
      expect(summary.isLow, isFalse);
    });
  });

  group('DashboardRepository', () {
    test('getDashboard() returns summary from repository', () async {
      final expected = DashboardSummary(
        floatBalanceMinorUnits: 5000000,
        floatCurrencyCode: 'BWP',
        lowFloatThresholdMinorUnits: 500000,
        todayCashInCount: 12,
        todayCashInAmountMinorUnits: 1200000,
        todayCashOutCount: 8,
        todayCashOutAmountMinorUnits: 900000,
        updatedAt: DateTime.parse('2026-10-05T08:30:00Z'),
      );
      when(() => mockRepository.getDashboard()).thenAnswer((_) async => expected);

      final result = await mockRepository.getDashboard();
      expect(result.floatBalanceMinorUnits, 5000000);
      expect(result.isLow, isFalse);
    });
  });
}
