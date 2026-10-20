enum AgentStatus { active, suspended, pending }

enum KycStatus { verified, pending, rejected }

class Agent {
  final String id;
  final String phone;
  final String firstName;
  final String lastName;
  final String locationCode;
  final AgentStatus status;
  final KycStatus kycStatus;
  final DateTime createdAt;
  final String? email;

  const Agent({
    required this.id,
    required this.phone,
    required this.firstName,
    required this.lastName,
    required this.locationCode,
    required this.status,
    required this.kycStatus,
    required this.createdAt,
    this.email,
  });

  String get fullName => '$firstName $lastName';

  /// Alias for [phone] — used by profile screen.
  String get phoneNumber => phone;
}
