import 'package:shared_payment/shared_payment.dart';

// DEV_MOCK: pending E32 agent auth module wiring for real PIN/biometric step-up.
class AgentStepUpService implements StepUpService {
  AgentStepUpService();

  @override
  Future<String> acquireToken({required String scope}) async {
    // never persisted; discarded after forwarding to notifier
    return 'dev-mock-step-up-token-scope-$scope';
  }
}
