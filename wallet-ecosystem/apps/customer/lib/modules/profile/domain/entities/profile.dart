class CustomerProfile {
  final String id;
  final String firstName;
  final String lastName;
  final String email;
  final String? phoneNumber;
  final String? avatarUrl;
  final String kycStatus;
  final String? nationalId;
  final DateTime? dateOfBirth;
  final DateTime createdAt;

  const CustomerProfile({
    required this.id,
    required this.firstName,
    required this.lastName,
    required this.email,
    this.phoneNumber,
    this.avatarUrl,
    required this.kycStatus,
    this.nationalId,
    this.dateOfBirth,
    required this.createdAt,
  });

  String get fullName => '$firstName $lastName';
}

class UpdateProfileRequest {
  final String? firstName;
  final String? lastName;
  const UpdateProfileRequest({this.firstName, this.lastName});

  Map<String, dynamic> toJson() => {
        if (firstName != null) 'first_name': firstName,
        if (lastName != null) 'last_name': lastName,
      };
}
