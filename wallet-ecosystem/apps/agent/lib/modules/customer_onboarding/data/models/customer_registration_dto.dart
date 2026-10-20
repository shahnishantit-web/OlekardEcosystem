import 'package:wallet_agent/modules/customer_onboarding/domain/entities/customer_profile.dart';

class CustomerRegistrationRequestDto {
  final String firstName;
  final String lastName;
  final String phoneNumber;
  final String nationalId;
  final String? email;

  const CustomerRegistrationRequestDto({
    required this.firstName,
    required this.lastName,
    required this.phoneNumber,
    required this.nationalId,
    this.email,
  });

  factory CustomerRegistrationRequestDto.fromProfile(CustomerProfile p) =>
      CustomerRegistrationRequestDto(
        firstName: p.firstName,
        lastName: p.lastName,
        phoneNumber: p.phoneNumber,
        nationalId: p.nationalId,
        email: p.email,
      );

  Map<String, dynamic> toJson() => {
        'first_name': firstName,
        'last_name': lastName,
        'phone_number': phoneNumber,
        'national_id': nationalId,
        if (email != null) 'email': email,
      };
}

class RegistrationResultDto {
  final String walletId;
  final String walletNumber;
  final String customerName;
  final String referenceNumber;
  final DateTime createdAt;

  const RegistrationResultDto({
    required this.walletId,
    required this.walletNumber,
    required this.customerName,
    required this.referenceNumber,
    required this.createdAt,
  });

  factory RegistrationResultDto.fromJson(Map<String, dynamic> json) {
    return RegistrationResultDto(
      walletId: json['wallet_id'] as String,
      walletNumber: json['wallet_number'] as String,
      customerName: json['customer_name'] as String,
      referenceNumber: json['reference_number'] as String,
      createdAt: DateTime.parse(json['created_at'] as String),
    );
  }

  RegistrationResult toEntity() => RegistrationResult(
        walletId: walletId,
        walletNumber: walletNumber,
        customerName: customerName,
        referenceNumber: referenceNumber,
        createdAt: createdAt,
      );
}
