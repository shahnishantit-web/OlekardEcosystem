class LoginRequestDto {
  final String phone;
  final String pinHash;

  const LoginRequestDto({required this.phone, required this.pinHash});

  Map<String, dynamic> toJson() => {
        'phone': phone,
        'pin_hash': pinHash,
      };
}

class ForgotPinRequestDto {
  final String phone;
  const ForgotPinRequestDto({required this.phone});
  Map<String, dynamic> toJson() => {'phone': phone};
}

class ResetPinRequestDto {
  final String otpReference;
  final String otpCode;
  final String newPinHash;
  const ResetPinRequestDto({
    required this.otpReference,
    required this.otpCode,
    required this.newPinHash,
  });
  Map<String, dynamic> toJson() => {
        'otp_reference': otpReference,
        'otp_code': otpCode,
        'new_pin_hash': newPinHash,
      };
}

class ChangePinRequestDto {
  final String currentPinHash;
  final String newPinHash;
  const ChangePinRequestDto({
    required this.currentPinHash,
    required this.newPinHash,
  });
  Map<String, dynamic> toJson() => {
        'current_pin_hash': currentPinHash,
        'new_pin_hash': newPinHash,
      };
}

class StepUpRequestDto {
  final String pinHash;
  const StepUpRequestDto({required this.pinHash});
  Map<String, dynamic> toJson() => {'pin_hash': pinHash};
}
