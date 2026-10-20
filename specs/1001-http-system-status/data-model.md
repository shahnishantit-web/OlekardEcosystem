# Ephemeral model
SystemStatus: status429|503|504, optional sanitized correlationId, optional bounded retryAfterSeconds.
Maintenance capability: {purpose:'maintenance',role:'superadmin',exp:unixseconds}; HMAC-SHA256 over base64urlpayload, maximum onehour remaining, configured secret>=32chars. HttpOnlycookie expireswithtoken. No persistent DBstate.
