export interface MaintenanceCapability {
    purpose: 'maintenance';
    role: 'superadmin';
    exp: number;
}
function encode(bytes: Uint8Array): string { return btoa(Array.from(bytes, b => String.fromCharCode(b)).join('')).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
function decode(value: string): Uint8Array<ArrayBuffer> { return Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0)); }
async function key(secret: string) { return crypto.subtle.importKey('raw', new TextEncoder().encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign', 'verify']); }
export async function signMaintenanceToken(secret: string, exp: number): Promise<string> {
    if (secret.length < 32)
        throw new Error('Maintenance signing secret must contain at least32 characters.');
    const payload = encode(new TextEncoder().encode(JSON.stringify({ purpose: 'maintenance', role: 'superadmin', exp })));
    const signature = await crypto.subtle.sign('HMAC', await key(secret), new TextEncoder().encode(payload));
    return payload + '.' + encode(new Uint8Array(signature));
}
export async function verifyMaintenanceToken(token: string | undefined, secret: string | undefined, now = Math.floor(Date.now() / 1000)): Promise<MaintenanceCapability | null> {
    if (token === undefined || secret === undefined || secret.length < 32 || token.length > 2048)
        return null;
    try {
        const parts = token.split('.');
        if (parts.length !== 2)
            return null;
        const [payload, signature] = parts;
        if (payload === undefined || signature === undefined || !/^[A-Za-z0-9_-]+$/.test(payload) || !/^[A-Za-z0-9_-]+$/.test(signature))
            return null;
        if (!await crypto.subtle.verify('HMAC', await key(secret), decode(signature), new TextEncoder().encode(payload)))
            return null;
        const data = JSON.parse(new TextDecoder().decode(decode(payload))) as Record<string, unknown>;
        if (Object.keys(data).length !== 3 || data.purpose !== 'maintenance' || data.role !== 'superadmin' || typeof data.exp !== 'number' || !Number.isSafeInteger(data.exp) || data.exp <= now || data.exp > now + 3600)
            return null;
        return { purpose: 'maintenance', role: 'superadmin', exp: data.exp };
    }
    catch {
        return null;
    }
}
