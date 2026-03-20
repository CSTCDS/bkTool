<?php
// mon-site/api/JwtHelper.php — Generate RS256 JWT for Enable Banking API
// Requires: openssl PHP extension, a private RSA key file, and the application ID (kid)

class JwtHelper
{
    /**
     * Generate a JWT token for Enable Banking API.
     *
     * @param string $appId      Application ID (kid) from Enable Banking control panel
     * @param string $privateKey PEM-encoded private RSA key content
     * @param int    $ttl        Token time-to-live in seconds (max 86400)
     * @return string            Signed JWT
     */
    public static function generate(string $appId, string $privateKey, int $ttl = 3600): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'RS256',
            'kid' => $appId
        ];

        $now = time();
        $payload = [
            'iss' => 'enablebanking.com',
            'aud' => 'api.enablebanking.com',
            'iat' => $now,
            'exp' => $now + min($ttl, 86400)
        ];

        $segments = [];
        $segments[] = self::base64url(json_encode($header));
        $segments[] = self::base64url(json_encode($payload));

        $signingInput = implode('.', $segments);

        $signature = '';
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new RuntimeException('Failed to load private key: ' . openssl_error_string());
        }
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign JWT: ' . openssl_error_string());
        }

        $segments[] = self::base64url($signature);
        return implode('.', $segments);
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
