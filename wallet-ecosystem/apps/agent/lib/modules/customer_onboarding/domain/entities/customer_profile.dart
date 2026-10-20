class CustomerProfile {
  final String firstName;
  final String lastName;
  final String phoneNumber;
  final String nationalId;
  final String? email;

  const CustomerProfile({
    required this.firstName,
    required this.lastName,
    required this.phoneNumber,
    required this.nationalId,
    this.email,
  });

  String get fullName => '$firstName $lastName';
}

class RegistrationResult {
  final String walletId;
  final String walletNumber;
  final String customerName;
  final String referenceNumber;
  final DateTime createdAt;

  const RegistrationResult({
    required this.walletId,
    required this.walletNumber,
    required this.customerName,
    required this.referenceNumber,
    required this.createdAt,
  });
}
