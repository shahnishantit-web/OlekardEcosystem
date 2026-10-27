import 'package:flutter_riverpod/flutter_riverpod.dart';

abstract interface class StepUpService {
  Future<String> acquireToken({required String scope});
}

final stepUpServiceProvider = Provider<StepUpService>((ref) {
  throw UnimplementedError(
      'Override stepUpServiceProvider in your ProviderScope with '
      'stepUpServiceProvider.overrideWith((ref) => YourStepUpService(ref))');
});
