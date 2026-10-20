enum KycStatus { none, pending, approved, rejected }

class Customer {
  final String id;
  final String phone;
  final String? email;
  final String firstName;
  final String lastName;
  final String? avatarUrl;
  final KycStatus kycStatus;
  final String locale;
  final DateTime createdAt;

  const Customer({
    required this.id,
    required this.phone,
    this.email,
    required this.firstName,
    required this.lastName,
    this.avatarUrl,
    required this.kycStatus,
    required this.locale,
    required this.createdAt,
  });

  String get fullName => '$firstName $lastName';
}
