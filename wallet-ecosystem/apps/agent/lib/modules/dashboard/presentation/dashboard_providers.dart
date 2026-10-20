import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/modules/dashboard/data/repositories/dashboard_repository_impl.dart';
import 'package:wallet_agent/modules/dashboard/domain/entities/dashboard_summary.dart';
import 'package:wallet_agent/modules/dashboard/domain/repositories/dashboard_repository.dart';

final dashboardRepositoryProvider = Provider<DashboardRepository>((ref) {
  final dio = createDio(null);
  return DashboardRepositoryImpl(dio: dio);
});

final dashboardProvider = FutureProvider.autoDispose<DashboardSummary>((ref) {
  return ref.read(dashboardRepositoryProvider).getDashboard();
});
