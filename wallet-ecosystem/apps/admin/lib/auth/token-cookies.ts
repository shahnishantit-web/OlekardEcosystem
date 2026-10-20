// JWTs with a complete admin permission set exceed the browser's per-cookie limit.
// This is storage only; backend signature/session/permission validation is unchanged.
export const TOKEN_COOKIE = 'admin_token';
export const TOKEN_CHUNK_SIZE = 3000;
export const MAX_TOKEN_CHUNKS = 4;
interface CookieReader { get(name: string): { value: string } | undefined }

export function tokenCookieParts(token: string): Array<{ name: string; value: string }> {
  if (token.length > TOKEN_CHUNK_SIZE * MAX_TOKEN_CHUNKS)
    throw new Error('The administrator session is too large to store. Contact support.');
  if (token.length <= TOKEN_CHUNK_SIZE) return [{ name: TOKEN_COOKIE, value: token }];
  const parts = Array.from({ length: Math.ceil(token.length / TOKEN_CHUNK_SIZE) }, (_, i) => ({
    name: `${TOKEN_COOKIE}.${i}`, value: token.slice(i * TOKEN_CHUNK_SIZE, (i + 1) * TOKEN_CHUNK_SIZE),
  }));
  // Write the marker last, after every chunk has been stored.
  return [...parts, { name: TOKEN_COOKIE, value: `chunks:${parts.length}` }];
}

export function readTokenCookie(cookies: CookieReader): string | undefined {
  const raw = cookies.get(TOKEN_COOKIE)?.value;
  if (raw === undefined || raw === '') return raw;
  // Cookie values are written with encodeURIComponent; decode before parsing.
  let value: string;
  try { value = decodeURIComponent(raw); } catch { value = raw; }
  if (!value.startsWith('chunks:')) return value;
  if (!/^chunks:[2-4]$/.test(value)) return undefined;
  const count = Number(value.slice(7));
  let token = '';
  for (let i = 0; i < count; i++) {
    // JWT chunk values are base64url (URL-safe) — stored without encoding.
    const chunk = cookies.get(`${TOKEN_COOKIE}.${i}`)?.value;
    if (chunk === undefined || chunk === '' || chunk.length > TOKEN_CHUNK_SIZE) return undefined;
    token += chunk;
  }
  return token;
}

export const TOKEN_COOKIE_NAMES = [TOKEN_COOKIE, ...Array.from({ length: MAX_TOKEN_CHUNKS }, (_, i) => `${TOKEN_COOKIE}.${i}`)];
