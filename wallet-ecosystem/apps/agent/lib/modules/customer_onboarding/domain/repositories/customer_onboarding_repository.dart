import 'package:wallet_agent/modules/customer_onboarding/domain/entities/customer_profile.dart';

abstract class CustomerOnboardingRepository {
  Future<RegistrationResult> registerCustomer(CustomerProfile profile);
  Future<void> initiateKyc(String customerId);
  Future<CustomerStatus> getCustomerStatus(String customerId);
}

class CustomerStatus {
  final String customerId;
  final String customerName;
  final String phoneNumber;
  final String accountStatus;
  final String kycStatus;

  const CustomerStatus({
    required this.customerId,
    required this.customerName,
    required this.phoneNumber,
    required this.accountStatus,
    required this.kycStatus,
  });

  bool get isKycVerified => kycStatus == 'verified';
  bool get isKycPending =>
      kycStatus == 'pending' || kycStatus == 'under_review';
}
