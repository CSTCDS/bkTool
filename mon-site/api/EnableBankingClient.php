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

        $auth = base64_encode($this->clientId . ':' . $this->clientSecret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $auth,
            'Accept: application/json'
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . $err);
        }
        curl_close($ch);

        $data = json_decode($resp, true);
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
<?php
// api/EnableBankingClient.php — minimal client for Enable Banking (sandbox/supports Basic auth)

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

        $auth = base64_encode($this->clientId . ':' . $this->clientSecret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $auth,
            'Accept: application/json'
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . $err);
        }
        curl_close($ch);

        $data = json_decode($resp, true);
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
