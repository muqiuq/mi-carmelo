<?php
/**
 * Minimal self-contained Web Push library.
 * Uses PHP openssl extension for AES-GCM and JWT signing,
 * and the openssl CLI for ECDH key derivation (P-256).
 * No external dependencies required.
 */

function webpush_base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webpush_base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Generate or load VAPID key pair. Stored as JSON in the db directory.
 */
function getVapidKeys($dbDir) {
    $file = $dbDir . '/vapid.json';
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true);
    }

    $key = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC
    ]);

    openssl_pkey_export($key, $privatePem);
    $details = openssl_pkey_get_details($key);

    // Uncompressed public key: 0x04 || x || y
    $publicKeyRaw = "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                           . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    $vapid = [
        'publicKey' => webpush_base64url_encode($publicKeyRaw),
        'privateKeyPem' => $privatePem
    ];

    file_put_contents($file, json_encode($vapid));
    return $vapid;
}

/**
 * Convert a raw uncompressed EC P-256 public key (65 bytes) to PEM format.
 */
function rawPublicKeyToPem($raw) {
    // DER header for SubjectPublicKeyInfo with EC P-256
    $header = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $der = $header . $raw;
    $b64 = chunk_split(base64_encode($der), 64, "\n");
    return "-----BEGIN PUBLIC KEY-----\n" . $b64 . "-----END PUBLIC KEY-----\n";
}

/**
 * Perform ECDH key derivation using openssl CLI.
 * Returns the 32-byte shared secret.
 */
function ecdh_derive($localPrivKeyPem, $peerPublicKeyRaw) {
    $peerPubPem = rawPublicKeyToPem($peerPublicKeyRaw);

    $tmpDir = sys_get_temp_dir() . '/webpush_' . bin2hex(random_bytes(8));
    mkdir($tmpDir, 0700, true);

    $privFile = $tmpDir . '/priv.pem';
    $pubFile = $tmpDir . '/pub.pem';
    $outFile = $tmpDir . '/shared.bin';

    file_put_contents($privFile, $localPrivKeyPem);
    file_put_contents($pubFile, $peerPubPem);

    $cmd = sprintf(
        'openssl pkeyutl -derive -inkey %s -peerkey %s -out %s 2>&1',
        escapeshellarg($privFile),
        escapeshellarg($pubFile),
        escapeshellarg($outFile)
    );

    exec($cmd, $output, $returnCode);

    $shared = '';
    if ($returnCode === 0 && file_exists($outFile)) {
        $shared = file_get_contents($outFile);
    }

    // Cleanup temp files
    @unlink($privFile);
    @unlink($pubFile);
    @unlink($outFile);
    @rmdir($tmpDir);

    if (empty($shared)) {
        throw new RuntimeException('ECDH key derivation failed: ' . implode(' ', $output));
    }

    return $shared;
}

function hkdf_extract($salt, $ikm) {
    return hash_hmac('sha256', $ikm, $salt, true);
}

function hkdf_expand($prk, $info, $length) {
    $t = '';
    $output = '';
    $counter = 1;
    while (strlen($output) < $length) {
        $t = hash_hmac('sha256', $t . $info . chr($counter), $prk, true);
        $output .= $t;
        $counter++;
    }
    return substr($output, 0, $length);
}

/**
 * Encrypt a push notification payload per RFC 8291 (aes128gcm).
 * Returns the encrypted body to send to the push endpoint.
 */
function encryptPayload($payload, $p256dhBase64url, $authBase64url) {
    $userPublicKey = webpush_base64url_decode($p256dhBase64url); // 65 bytes
    $authSecret = webpush_base64url_decode($authBase64url);      // 16 bytes

    // Generate ephemeral EC key pair
    $localKey = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC
    ]);

    openssl_pkey_export($localKey, $localPrivPem);
    $localDetails = openssl_pkey_get_details($localKey);
    $localPublicKey = "\x04" . str_pad($localDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                             . str_pad($localDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    // ECDH shared secret
    $ecdhSecret = ecdh_derive($localPrivPem, $userPublicKey);

    // RFC 8291: combine ECDH secret with auth secret
    $ikm_info = "WebPush: info\x00" . $userPublicKey . $localPublicKey;
    $prk = hkdf_extract($authSecret, $ecdhSecret);
    $ikm = hkdf_expand($prk, $ikm_info, 32);

    // RFC 8188: derive content encryption key and nonce
    $salt = random_bytes(16);
    $prk2 = hkdf_extract($salt, $ikm);
    $cek = hkdf_expand($prk2, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = hkdf_expand($prk2, "Content-Encoding: nonce\x00", 12);

    // Encrypt: payload + 0x02 padding delimiter (last record)
    $padded = $payload . "\x02";
    $tag = '';
    $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    $encrypted = $ciphertext . $tag;

    // Build aes128gcm content coding header (RFC 8188 Section 2)
    $recordSize = 4096;
    $body = $salt                           // 16 bytes salt
          . pack('N', $recordSize)          // 4 bytes record size
          . chr(strlen($localPublicKey))    // 1 byte key ID length (65)
          . $localPublicKey                 // 65 bytes sender public key
          . $encrypted;                     // ciphertext + tag

    return $body;
}

/**
 * Convert DER-encoded ECDSA signature to raw R||S (64 bytes) for JWT ES256.
 */
function derSignatureToRaw($der) {
    $pos = 2; // skip SEQUENCE tag + length byte
    // Handle 2-byte length
    if (ord($der[1]) & 0x80) {
        $pos++;
    }

    // Read R
    $pos++; // skip INTEGER tag (0x02)
    $rLen = ord($der[$pos++]);
    $r = substr($der, $pos, $rLen);
    $pos += $rLen;

    // Read S
    $pos++; // skip INTEGER tag (0x02)
    $sLen = ord($der[$pos++]);
    $s = substr($der, $pos, $sLen);

    // Pad/trim to exactly 32 bytes each
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

    return $r . $s;
}

/**
 * Create a VAPID JWT for the given push endpoint.
 */
function createVapidJwt($endpoint, $vapidPrivPem) {
    $parsed = parse_url($endpoint);
    $audience = $parsed['scheme'] . '://' . $parsed['host'];
    if (isset($parsed['port'])) {
        $audience .= ':' . $parsed['port'];
    }

    $header = webpush_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = webpush_base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => 'mailto:admin@micarmelo.local'
    ]));

    $data = $header . '.' . $payload;

    $key = openssl_pkey_get_private($vapidPrivPem);
    openssl_sign($data, $derSig, $key, OPENSSL_ALGO_SHA256);

    $rawSig = derSignatureToRaw($derSig);

    return $data . '.' . webpush_base64url_encode($rawSig);
}

/**
 * Send a push notification to a single subscription.
 * Returns ['httpCode' => int, 'success' => bool].
 */
function sendWebPush($endpoint, $p256dh, $auth, $payload, $vapidKeys) {
    $encrypted = encryptPayload($payload, $p256dh, $auth);
    $jwt = createVapidJwt($endpoint, $vapidKeys['privateKeyPem']);

    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: 86400',
        'Authorization: vapid t=' . $jwt . ', k=' . $vapidKeys['publicKey'],
        'Content-Length: ' . strlen($encrypted)
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $encrypted);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [
        'httpCode' => $httpCode,
        'success' => $httpCode >= 200 && $httpCode < 300,
        'response' => $response
    ];
}
