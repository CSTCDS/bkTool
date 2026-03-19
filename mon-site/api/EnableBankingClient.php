<?php
// api/EnableBankingClient.php — minimal client for Enable Banking

class EnableBankingClient
{
    private $base;
    private $clientId;
    private $clientSecret;

    public function __construct(array $config)
    {
        $this->base = rtrim($config['enable_api_base'] ?? '', '/') ?: 'https://api.sandbox.enablebanking.com';
        $this->clientId = $config['enable_client_id'] ?? '';
        $this->clientSecret = $config['enable_client_secret'] ?? '';
    }

    private function request(string $method, string $path)
    {
        $url = $this->base . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        // timeouts to avoid long hanging requests
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $auth = base64_encode($this->clientId . ':' . $this->clientSecret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $auth,
            'Accept: application/json',
            'User-Agent: bkTool/1.0'
        ]);

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
            // Return raw response when JSON parse fails
            return ['status' => $http, 'body' => null, 'raw' => $resp];
        }
        return ['status' => $http, 'body' => $data];
    }

    public function getAccounts()
    {
        return $this->request('GET', '/accounts');
    }

    public function getAccountTransactions(string $accountId)
    {
        $path = '/accounts/' . urlencode($accountId) . '/transactions';
        return $this->request('GET', $path);
    }
}

