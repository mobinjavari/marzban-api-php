<?php

class xuiMarzban
{
    public string|null $auth_token = null;

    const Method_POST = 'POST';
    const Method_GET = 'GET';
    const Method_PUT = 'PUT';
    const Method_DELETE = 'DELETE';

    public function __construct(
        private readonly string $host,
        string $username,
        string $password
    )
    {
        $this->auth_token = $this->authToken($username, $password);

    }

    public function getUsers(): array
    {
        if (is_null($this->auth_token))
            return $this->sendResponse(404);
        return $this->sendRequest('/users');
    }

    private function authToken(string $username, string $password): string|null
    {
        $data = http_build_query([
            'username' => $username,
            'password' => $password
        ]);
        $headers = [
            'Content-Type: application/x-www-form-urlencoded'
        ];
        $res = $this->sendRequest('/admin/token', data: $data, method: self::Method_POST, headers: $headers);

        if ($res['status'] == 200) {
            return $res['data']['access_token'];
        }

        return null;
    }

    private function sendResponse(
        int $http_code,
        array|object|string|null $data = null
    ): array
    {
        return [
            'status' => $http_code,
            'data' => $data ?: null
        ];
    }

    private function sendRequest(
        string $path,
        array|object|string $data = [],
        string $method = self::Method_GET,
        array $headers = []
    ): array
    {
        if (filter_var($this->host, FILTER_VALIDATE_URL)) {
            $headers[] = 'Authorization: Bearer ' . $this->auth_token;
            $options = [
                CURLOPT_URL =>  $this->host . "api{$path}",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT  => 10,
                CURLOPT_SSL_VERIFYPEER   => false,
                CURLOPT_SSL_VERIFYHOST   => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER => $headers,
            ];

            if ($method == self::Method_POST || $method == self::Method_PUT) {
                $options[CURLOPT_CUSTOMREQUEST] = $method;
                $options[CURLOPT_POSTFIELDS] = $data;
            }

            $curl = curl_init();
            curl_setopt_array($curl, $options);
            $response = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $data = json_decode($response, true) ?: [];

            return $this->sendResponse($http_code, $data);
        }

        return $this->sendResponse(404);
    }
}