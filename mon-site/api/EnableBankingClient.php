<?php
// api/EnableBankingClient.php — client for Enable Banking API (JWT RS256 auth)

require_once __DIR__ . '/JwtHelper.php';

class EnableBankingClient
{
    private string $base;
    private string $jwt;

    /**
     * @param array $config Must contain:
     *   - enable_app_id: application ID (kid)
     *   - enable_private_key OR enable_private_key_path: PEM RSA private key
     *   - enable_api_base (optional, default https://api.enablebanking.com)
     */
    public function __construct(array $config)
    {
        $this->base = rtrim($config['enable_api_base'] ?? '', '/') ?: 'https://api.enablebanking.com';

        $appId = $config['enable_app_id'] ?? '';
        $privateKey = $config['enable_private_key'] ?? null;
        if (!$privateKey && !empty($config['enable_private_key_path'])) {
            $path = $config['enable_private_key_path'];
            if (!file_exists($path)) {
                throw new RuntimeException('Private key file not found: ' . $path);
            }
            $privateKey = file_get_contents($path);
        }
        if (!$appId || !$privateKey) {
            throw new RuntimeException('enable_app_id and enable_private_key(_path) are required in config');
        }

        $this->jwt = JwtHelper::generate($appId, $privateKey);
    }

    /**
     * Generic HTTP request with JWT Bearer auth.
     */
    public function request(string $method, string $path, ?array $jsonBody = null): array
    {
        $url = $this->base . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $headers = [
            'Authorization: Bearer ' . $this->jwt,
            'Accept: application/json',
            'User-Agent: bkTool/1.0'
        ];

        if ($jsonBody !== null) {
            $body = json_encode($jsonBody);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => null, 'error' => 'cURL error: ' . $err];
        }
        curl_close($ch);

        $data = json_decode($resp, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return ['status' => $http, 'body' => null, 'raw' => $resp];
        }
        return ['status' => $http, 'body' => $data];
    }

    /** POST /auth — start user authorization */
    public function startAuth(array $authRequest): array
    {
        return $this->request('POST', '/auth', $authRequest);
    }

    /** POST /sessions — exchange authorization code for session */
    public function createSession(string $code): array
    {
        return $this->request('POST', '/sessions', ['code' => $code]);
    }

    /** GET /sessions/{id} */
    public function getSession(string $sessionId): array
    {
        return $this->request('GET', '/sessions/' . urlencode($sessionId));
    }

    /** GET /aspsps */
    public function getAspsps(?string $country = null): array
    {
        $path = '/aspsps';
        if ($country) $path .= '?country=' . urlencode($country);
        return $this->request('GET', $path);
    }

    /** GET /accounts/{id}/balances */
    public function getAccountBalances(string $accountId): array
    {
        return $this->request('GET', '/accounts/' . urlencode($accountId) . '/balances');
    }

    /** GET /accounts/{id}/transactions */
    public function getAccountTransactions(string $accountId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $path = '/accounts/' . urlencode($accountId) . '/transactions';
        $params = [];
        if ($dateFrom) $params['date_from'] = $dateFrom;
        if ($dateTo) $params['date_to'] = $dateTo;
        if ($params) $path .= '?' . http_build_query($params);
        return $this->request('GET', $path);
    }

    /** GET /application — verify app registration */
    public function getApplication(): array
    {
        return $this->request('GET', '/application');
    }
}

