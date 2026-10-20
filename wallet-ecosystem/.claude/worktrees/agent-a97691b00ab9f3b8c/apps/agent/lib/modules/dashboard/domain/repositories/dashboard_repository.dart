import 'package:wallet_agent/modules/dashboard/domain/entities/dashboard_summary.dart';

abstract class DashboardRepository {
  Future<DashboardSummary> getDashboard();
}
