import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/modules/customer_onboarding/data/repositories/customer_onboarding_repository_impl.dart';
import 'package:wallet_agent/modules/customer_onboarding/domain/entities/customer_profile.dart';
import 'package:wallet_agent/modules/customer_onboarding/domain/repositories/customer_onboarding_repository.dart';

final customerOnboardingRepositoryProvider =
    Provider<CustomerOnboardingRepository>((ref) {
  return CustomerOnboardingRepositoryImpl(dio: createDio(null));
});

class RegistrationNotifier extends StateNotifier<RegistrationResult?> {
  RegistrationNotifier() : super(null);
  void setResult(RegistrationResult result) => state = result;
  void clear() => state = null;
}

final registrationResultProvider =
    StateNotifierProvider<RegistrationNotifier, RegistrationResult?>(
  (_) => RegistrationNotifier(),
);
