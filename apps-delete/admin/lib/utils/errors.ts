export interface ApiErrorDetail {
  field?: string;
  code: string;
  message: string;
}

export interface ApiError {
  success: false;
  data: null;
  error: {
    code: string;
    message: string;
    details: ApiErrorDetail[];
  };
  correlation_id: string;
  meta: null;
}

export interface ValidationError extends ApiError {
  error: ApiError['error'] & {
    code: 'VALIDATION_ERROR';
  };
}

export function isApiError(value: unknown): value is ApiError {
  return (
    typeof value === 'object' &&
    value !== null &&
    'success' in value &&
    (value as ApiError).success === false &&
    'error' in value &&
    typeof (value as ApiError).error === 'object'
  );
}

export function isValidationError(value: unknown): value is ValidationError {
  return isApiError(value) && value.error.code === 'VALIDATION_ERROR';
}
