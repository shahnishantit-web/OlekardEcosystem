import type { ApiResponse } from '@/lib/api/client';

export function isApiError(
  response: ApiResponse<unknown>,
): response is ApiResponse<never> & { error: NonNullable<ApiResponse<never>['error']> } {
  return response.success === false && response.error !== null;
}

export function isValidationError(
  response: ApiResponse<unknown>,
): boolean {
  return isApiError(response) && response.error?.code === 'VALIDATION_ERROR';
}
