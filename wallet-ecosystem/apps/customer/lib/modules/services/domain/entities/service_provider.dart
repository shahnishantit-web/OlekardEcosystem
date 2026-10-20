enum ServiceFieldType { text, numeric, phone, select }

class ServiceCategory {
  final String id;
  final String name;
  final String iconUrl;
  final String description;
  final int providerCount;

  const ServiceCategory({
    required this.id,
    required this.name,
    required this.iconUrl,
    required this.description,
    required this.providerCount,
  });
}

class ServiceField {
  final String key;
  final String label;
  final ServiceFieldType type;
  final bool required;
  final String? hint;
  final List<String>? options;

  const ServiceField({
    required this.key,
    required this.label,
    required this.type,
    required this.required,
    this.hint,
    this.options,
  });
}

class ServiceProvider {
  final String id;
  final String categoryId;
  final String name;
  final String logoUrl;
  final String description;
  final List<ServiceField> requiredFields;
  final int feeMinorUnits;
  final String currencyCode;
  final bool isActive;

  const ServiceProvider({
    required this.id,
    required this.categoryId,
    required this.name,
    required this.logoUrl,
    required this.description,
    required this.requiredFields,
    required this.feeMinorUnits,
    required this.currencyCode,
    required this.isActive,
  });
}

class ServicePayment {
  final String id;
  final String referenceNumber;
  final String providerId;
  final String providerName;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final ServicePaymentStatus status;
  final Map<String, String> fieldValues;
  final DateTime createdAt;

  const ServicePayment({
    required this.id,
    required this.referenceNumber,
    required this.providerId,
    required this.providerName,
    required this.amountMinorUnits,
    required this.feeMinorUnits,
    required this.currencyCode,
    required this.status,
    required this.fieldValues,
    required this.createdAt,
  });
}

enum ServicePaymentStatus { pending, processing, completed, failed }

class ServicePurchaseRequestDto {
  final String idempotencyKey;
  final String providerId;
  final int amountMinorUnits;
  final Map<String, String> fieldValues;

  const ServicePurchaseRequestDto({
    required this.idempotencyKey,
    required this.providerId,
    required this.amountMinorUnits,
    required this.fieldValues,
  });

  Map<String, dynamic> toJson() => {
        'provider_id': providerId,
        'amount_minor_units': amountMinorUnits,
        'field_values': fieldValues,
      };
}
