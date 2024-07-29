<?php

class xuiMarzban
{
    private string $host = '';

    private string|null $auth_token = null;

    private array $system = [];

    private array $inbounds = [
        'vmess' => [
            "VMess TCP",
            "VMess Websocket"
        ],
        'vless' => [
            "VLESS TCP REALITY",
            "VLESS GRPC REALITY"
        ],
        'shadowsocks' => [
            "Shadowsocks TCP"
        ]
    ];

    const Method_POST = 'POST';
    const Method_GET = 'GET';
    const Method_PUT = 'PUT';
    const Method_DELETE = 'DELETE';

    public function __construct(
        string $host,
        string $username,
        string $password
    )
    {
        $this->host = $this->formatServerUrl($host);
        $this->auth_token = $this->authToken($username, $password);
        $this->system = $this->system();
    }

    public function system(): array
    {
        if ($this->system)
            return $this->system;

        $system = $this->sendRequest('/system');

        if ($system['status'] == 200) {
            $this->system = $system['data'];
        }

        return $this->system;
    }

    public function getUsers(): array
    {
        $users = $this->sendRequest('/users');

        return $users['status'] == 200 ? $users['data'] : [];
    }

    public function getUser(string $username): array
    {
        $user = $this->sendRequest("/user/$username");

        return $user['status'] == 200 ? $user['data'] : [];
    }

    public function delUser(string $username): array
    {
        $delete = $this->sendRequest("/user/$username", method: self::Method_DELETE);

        return $delete['status'] == 200 ? $delete['data'] : [];
    }

    private function proxies(
        bool $vmess = false,
        bool $vless = false,
        bool $shadow_socks = false
    ): array
    {
        $proxies = [];

        if ($vmess)
            $proxies['vmess'] = ['id' => $this->genUserId()];

        if ($vless)
            $proxies['vless'] = [
                'id' => $this->genUserId(),
                'flow' => ''
            ];

        if ($shadow_socks)
            $proxies['shadowsocks'] = [
                'password' => $this->randomString(6),
                'method' => 'chacha20-ietf-poly1305'
            ];

        return $proxies;
    }

    private function inbounds(
        bool $vmess = false,
        bool $vless = false,
        bool $shadow_socks = false
    ): array
    {
        $inbounds = [];

        if ($vmess)
            $inbounds['vmess'] = $this->inbounds['vmess'];

        if ($vless)
            $inbounds['vless'] = $this->inbounds['vless'];

        if ($shadow_socks)
            $inbounds['shadowsocks'] = $this->inbounds['shadowsocks'];

        return $inbounds;
    }

    public function addUser(
        string $username,
        float $volume = 0,
        int $days = 0,
        bool $status = true,
        string $note = '',
        bool $vless = false,
        bool $vmess = false,
        bool $shadow_socks = false
    )
    {
        $volume *= 1024 * 1024 * 1024;
        $days *= 60 * 60 * 24;
        $data = json_encode([
            'username' => $username,
            'proxies' => $this->proxies(vmess: $vmess, vless: $vless, shadow_socks: $shadow_socks),
            'inbounds' => $this->inbounds(vmess: $vmess, vless: $vless, shadow_socks: $shadow_socks),
            'expire' => time() + $days,
            'data_limit' => $volume,
            'data_limit_reset_strategy' => 'no_reset',
            'status' => $status ? 'active' : 'disabled',
            'note' => $note,
            'on_hold_timeout' => null,
            'on_hold_expire_duration' => 0
        ]);
        $headers = [
            'Content-Type: application/json'
        ];
        $add = $this->sendRequest('/user', $data, self::Method_POST, $headers);

        return $add['status'] == 200 ? $add['data'] : [];
    }

    public function resetUserTraffic(string $username): array
    {
        $reset = $this->sendRequest("/user/$username/reset", method: self::Method_POST);

        return $reset['status'] == 200 ? $reset['data'] : [];
    }

    public function revokeUserSub(string $username): array
    {
        $revoke = $this->sendRequest("/user/$username/revoke", method: self::Method_POST);

        return $revoke['status'] == 200 ? $revoke['data'] : [];
    }

    public function editUser(string $username, array $update = []): array
    {
        $user = $this->getUser($username);

        if ($user['status'] == 200) {
            $user = $user['data'];
            $status = $update['status'] ?? $user['status'];
            $expire = $update['expire'] ?? $user['expire'];
            $add_days = $update['add_days'] ?? 0;
            $data_limit = $update['volume'] ?? $user['data_limit'];
            $add_volume = $update['volume'] ?? 0;
            $data = json_encode([
                'proxies' => $user['proxies'],
                'inbounds' => $user['inbounds'],
                'expire' => $expire + ($add_days * 60 * 60 * 24),
                'data_limit' => $data_limit + ($add_volume * 1024 * 1024 * 1024),
                'data_limit_reset_strategy' => $update['data_limit_reset_strategy'] ?? $user['data_limit_reset_strategy'],
                'status' => $status ? 'active' : 'disabled',
                'note' => $update['note'] ?? $user['note'],
                'on_hold_timeout' => $user['on_hold_timeout'],
                'on_hold_expire_duration' => $user['on_hold_expire_duration'],
            ]);
            $headers = [
                'Content-Type: application/json'
            ];
            $edit = $this->sendRequest("/user/$username", $data, self::Method_PUT, $headers);

            if ($edit['status'] == 200)
                return $edit['data'];
        }

        return [];
    }

    public static function formatServerUrl(string $url): string
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $addSlashUrl = str_ends_with($url, '/') ? $url : "$url/";

            if (str_starts_with($addSlashUrl, 'api://')) {
                $sslUrl = str_replace('api://', 'ssl://', $addSlashUrl);
                $httpsUrl = str_replace('ssl://', 'https://', $sslUrl);
                $httpUrl = str_replace('https://', 'http://', $httpsUrl);
                $conText = stream_context_create(['ssl' => ['capture_peer_cert' => true]]);
                $stream = stream_socket_client($sslUrl, $errNo, $errMg, 2, STREAM_CLIENT_CONNECT, $conText);

                if (!$stream) {
                    return $httpUrl; // SSL connection failed
                }

                $params = stream_context_get_params($stream);
                $cert = $params['options']['ssl']['peer_certificate'];

                if (!$cert) {
                    return $httpUrl; // No SSL certificate found
                }

                return $httpsUrl; // SSL certificate found
            }

            return $addSlashUrl;
        }

        return '';
    }


    /**
     * @throws \Random\RandomException
     */
    public function genUserId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function randomString(int $length): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    public static function formatBytes(
        int  $size,
        int  $format = 0,
        int  $precision = 0,
        bool $arrayReturn = false
    ): array|string
    {
        $base = log($size, 1024);
        $units = match ($format) {
            1 => ['بایت', 'کلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'], # Persian
            2 => ['B', 'K', 'M', 'G', 'T'],
            default => ['B', 'KB', 'MB', 'GB', 'TB']
        };

        if (!$size) return $arrayReturn ? [0, $units[1]] : "0 {$units[1]}";

        $result = pow(1024, $base - floor($base));
        $result = round($result, $precision);
        $unit = $units[floor($base)];

        return $arrayReturn ? [$result, $unit] : "$result $unit";
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
        $res = $this->sendRequest(
            '/admin/token',
            data: $data,
            method: self::Method_POST,
            headers: $headers,
            require_auth: false
        );

        return $res['status'] == 200 ? $res['data']['access_token'] : null;
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
        array $headers = [],
        bool $require_auth = true
    ): array
    {
        if (is_null($this->auth_token) && $require_auth)
            return $this->sendResponse(401);

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
                CURLOPT_CUSTOMREQUEST => $method
            ];

            if ($method == self::Method_POST || $method == self::Method_PUT) {
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