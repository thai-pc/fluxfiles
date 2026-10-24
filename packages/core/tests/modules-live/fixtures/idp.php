<?php
// A minimal but real OIDC provider for the SSO module: discovery, JWKS,
// an authorize page with a "Sign in" button, and a PKCE-checking token endpoint
// that mints RS256 id_tokens. Base URL = http://ff-idp:8191 for BOTH the core
// container (compose DNS) and the browser (Playwright maps ff-idp → 127.0.0.1).
require_once '/app/core/vendor/autoload.php';
$base = 'http://ff-idp:8191';
$client = getenv('IDP_CLIENT_ID') ?: 'fluxfiles-live';
$keyFile = '/tmp/idp-key.pem';
if (!is_file($keyFile)) {
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $pem);
    file_put_contents($keyFile, $pem);
}
$priv = (string) file_get_contents($keyFile);
$d = openssl_pkey_get_details(openssl_pkey_get_private($priv));
$b64 = static fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Cache-Control: no-store');

if ($uri === '/.well-known/openid-configuration') {
    header('Content-Type: application/json');
    echo json_encode(['issuer' => $base, 'authorization_endpoint' => "$base/authorize",
        'token_endpoint' => "$base/token", 'jwks_uri' => "$base/jwks",
        'response_types_supported' => ['code'], 'id_token_signing_alg_values_supported' => ['RS256'],
        'code_challenge_methods_supported' => ['S256']]);
    return;
}
if ($uri === '/jwks') {
    header('Content-Type: application/json');
    echo json_encode(['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'live',
        'n' => $b64($d['rsa']['n']), 'e' => $b64($d['rsa']['e'])]]]);
    return;
}
if ($uri === '/authorize') {
    $q = $_GET;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        // "Sign in": the code carries what the token endpoint will need.
        $code = $b64(json_encode(['sub' => $_POST['sub'] ?? 'alice', 'email' => $_POST['email'] ?? 'alice@example.test',
            'groups' => array_values(array_filter(explode(',', (string) ($_POST['groups'] ?? 'editors')))),
            'nonce' => $_POST['nonce'] ?? '', 'challenge' => $_POST['code_challenge'] ?? '', 'redirect' => $_POST['redirect_uri'] ?? '']));
        $sep = str_contains((string) $_POST['redirect_uri'], '?') ? '&' : '?';
        header('Location: ' . $_POST['redirect_uri'] . $sep . http_build_query(['code' => $code, 'state' => $_POST['state'] ?? '']), true, 302);
        return;
    }
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
    echo '<!doctype html><title>Test IdP</title><h1>Test IdP</h1><form method="post">';
    foreach (['redirect_uri', 'state', 'nonce', 'code_challenge'] as $k) {
        echo '<input type="hidden" name="' . $k . '" value="' . $h($q[$k] ?? '') . '">';
    }
    echo '<label>User <input name="sub" value="alice"></label><input type="hidden" name="email" value="alice@example.test">';
    echo '<input type="hidden" name="groups" value="editors"><button type="submit">Sign in</button></form>';
    return;
}
if ($uri === '/token' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    parse_str((string) file_get_contents('php://input'), $body);
    $c = json_decode((string) base64_decode(strtr((string) ($body['code'] ?? ''), '-_', '+/')), true);
    header('Content-Type: application/json');
    $verifier = (string) ($body['code_verifier'] ?? '');
    if (!is_array($c) || $c['challenge'] === '' || !hash_equals($c['challenge'], $b64(hash('sha256', $verifier, true)))) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant']);
        return;
    }
    $now = time();
    $claims = ['iss' => $base, 'aud' => $client, 'sub' => $c['sub'], 'email' => $c['email'], 'email_verified' => true,
        'groups' => $c['groups'], 'nonce' => $c['nonce'], 'iat' => $now, 'exp' => $now + 300];
    echo json_encode(['access_token' => 'at', 'token_type' => 'Bearer',
        'id_token' => \Firebase\JWT\JWT::encode($claims, $priv, 'RS256', 'live')]);
    return;
}
http_response_code(404);
