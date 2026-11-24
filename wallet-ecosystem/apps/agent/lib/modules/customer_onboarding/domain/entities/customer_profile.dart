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
  final String customerId;
  final String phone;
  final String status;
  final String customerName;

  const RegistrationResult({
    required this.customerId,
    required this.phone,
    required this.status,
    required this.customerName,
  });
}
