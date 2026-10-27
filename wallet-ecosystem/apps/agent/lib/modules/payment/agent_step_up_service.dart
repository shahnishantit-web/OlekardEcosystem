import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_payment/shared_payment.dart';

// DEV_MOCK: pending E32 agent auth module wiring for real PIN/biometric step-up.
class AgentStepUpService implements StepUpService {
  final Ref _ref;

  AgentStepUpService(this._ref);

  @override
  Future<String> acquireToken({required String scope}) async {
    // never persisted; discarded after forwarding to notifier
    return 'dev-mock-step-up-token-scope-$scope';
  }
}
