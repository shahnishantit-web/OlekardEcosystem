import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/di/providers.dart';
import '../data/event_operations_service.dart';

final eventOperationsServiceProvider = Provider<EventOperationsService>((ref) {
  final baseUrl = ref.watch(apiClientProvider).dio.options.baseUrl;
  return EventOperationsService(baseUrl: baseUrl);
});
