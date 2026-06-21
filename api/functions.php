<?php
/**
 * 公共函数库
 */

// ========== 响应函数 ==========

function json_response($code = 200, $data = []) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function success($data = [], $message = 'success') {
    json_response(200, array_merge(['success' => true, 'message' => $message], $data));
}

function error($message = 'error', $code = 400) {
    json_response($code, ['success' => false, 'error' => $message]);
}

// ========== 获取请求数据 ==========

function get_input() {
    $input = file_get_contents('php://input');
    if (!$input) {
        return [];
    }
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

function get_param($key, $default = null) {
    $input = get_input();
    return $input[$key] ?? $_GET[$key] ?? $_POST[$key] ?? $default;
}

// ========== JWT 实现 ==========

function jwt_encode($payload) {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRE;
    $payload = base64url_encode(json_encode($payload));

    $signature = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));

    return "$header.$payload.$signature";
}

function jwt_decode($token) {
    if (!$token) return null;

    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    list($header, $payload, $signature) = $parts;

    // 验证签名
    $expected = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload = json_decode(base64url_decode($payload), true);

    // 验证过期
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null;
    }

    return $payload;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}

// ========== 认证中间件 ==========

function get_token() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (preg_match('/Bearer\s+(\S+)/i', $auth, $matches)) {
        return $matches[1];
    }

    return $_GET['token'] ?? '';
}

function auth_required() {
    $token = get_token();
    if (!$token) {
        error('未提供认证令牌', 401);
    }

    $payload = jwt_decode($token);
    if (!$payload) {
        error('认证令牌无效或已过期', 401);
    }

    return $payload;
}

function admin_required() {
    $payload = auth_required();

    if (!isset($payload['role']) || $payload['role'] !== 'admin') {
        error('需要管理员权限', 403);
    }

    return $payload;
}

// ========== 工具函数 ==========

function format_bytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function random_str($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

function get_client_ip() {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

// ========== 验证函数 ==========

function validate_username($username) {
    return preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username);
}

function validate_password($password) {
    return strlen($password) >= 6 && strlen($password) <= 50;
}

function validate_email($email) {
    if (!$email) return true; // 允许为空
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}
