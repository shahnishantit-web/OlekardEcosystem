import 'package:dio/dio.dart';

import 'package:wallet_agent/modules/dashboard/domain/entities/dashboard_summary.dart';
import 'package:wallet_agent/modules/dashboard/domain/repositories/dashboard_repository.dart';
import 'package:wallet_agent/modules/dashboard/data/models/dashboard_dto.dart';

class DashboardRepositoryImpl implements DashboardRepository {
  final Dio _dio;

  DashboardRepositoryImpl({required Dio dio}) : _dio = dio;

  @override
  Future<DashboardSummary> getDashboard() async {
    final response = await _dio.get<Map<String, dynamic>>('/v1/agent/dashboard');
    return DashboardDto.fromJson(response.data!).toEntity();
  }
}
