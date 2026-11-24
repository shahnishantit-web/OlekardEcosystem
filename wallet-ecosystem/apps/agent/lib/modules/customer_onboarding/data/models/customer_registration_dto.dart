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
        'phone': phoneNumber,
        'id_type': 'national_id',
        'id_number': nationalId,
        if (email != null) 'email': email,
      };
}

class RegistrationResultDto {
  final String customerId;
  final String phone;
  final String status;
  final String customerName;

  const RegistrationResultDto({
    required this.customerId,
    required this.phone,
    required this.status,
    required this.customerName,
  });

  factory RegistrationResultDto.fromJson(Map<String, dynamic> json) {
    return RegistrationResultDto(
      customerId: json['id'] as String,
      phone: json['phone'] as String,
      status: json['status'] as String,
      customerName:
          '${json['first_name'] ?? ''} ${json['last_name'] ?? ''}'.trim(),
    );
  }

  RegistrationResult toEntity() => RegistrationResult(
        customerId: customerId,
        phone: phone,
        status: status,
        customerName: customerName,
      );
}
