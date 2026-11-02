<?php
/**
 * Callback/Webhook Listener Aulaa untuk WHMCS.
 *
 * Menerima HTTP POST notifikasi webhook dari engine Aulaa, memvalidasi
 * tanda tangan kriptografis HMAC-SHA256, lalu memperbarui status invoice
 * menjadi Paid (Lunas) secara otomatis dan idempoten.
 *
 * @package    Aulaa_WHMCS
 * @author     Aulaa.co
 * @copyright  2026 Aulaa.co
 * @license    MIT
 */

// Muat inisialisasi inti WHMCS
require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

$gatewayModuleName = 'aulaa';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Modul pembayaran Aulaa belum diaktifkan di WHMCS.']);
    exit;
}

// Ambil RAW payload body (wajib dipakai apa adanya untuk validasi signature)
$rawBody = file_get_contents('php://input');

// Ambil signature dari header X-Webhook-Signature
$signature = '';
if (isset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'])) {
    $signature = trim((string)$_SERVER['HTTP_X_WEBHOOK_SIGNATURE']);
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $headerKey => $headerVal) {
        if (strcasecmp($headerKey, 'X-Webhook-Signature') === 0) {
            $signature = trim((string)$headerVal);
            break;
        }
    }
}

$webhookSecret = (string)($gatewayParams['webhookSecret'] ?? '');
if (empty($webhookSecret)) {
    logTransaction(
        $gatewayParams['name'],
        ['error' => 'Webhook secret belum dikonfigurasi pada pengaturan modul WHMCS.'],
        'Configuration Error'
    );
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Webhook secret belum dikonfigurasi di WHMCS.']);
    exit;
}

// Verifikasi tanda tangan HMAC-SHA256 menggunakan constant-time comparison
$expectedSignature = hash_hmac('sha256', (string)$rawBody, $webhookSecret);
if (empty($signature) || !hash_equals($expectedSignature, $signature)) {
    logTransaction(
        $gatewayParams['name'],
        [
            'error'              => 'Tanda tangan webhook tidak valid',
            'received_signature' => $signature,
            'raw_body'           => $rawBody,
        ],
        'Invalid Signature'
    );
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Invalid signature']);
    exit;
}

// Parse data JSON notifikasi
$data = json_decode((string)$rawBody, true);
if (!is_array($data) || empty($data['order_id']) || empty($data['status'])) {
    logTransaction(
        $gatewayParams['name'],
        ['error' => 'Payload JSON tidak valid atau field wajib kosong', 'body' => $rawBody],
        'Invalid Payload'
    );
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Invalid payload format']);
    exit;
}

$orderRef = (string)$data['order_id'];
$status   = strtolower(trim((string)$data['status']));

// Petakan order_id kembali ke ID Invoice WHMCS. Format: whmcs-{invoiceId}-{token}
if (!preg_match('/^whmcs-(\d+)-/i', $orderRef, $matches)) {
    logTransaction(
        $gatewayParams['name'],
        ['message' => 'Notifikasi diabaikan: Format order_id bukan milik modul ini', 'order_id' => $orderRef],
        'Ignored (Unknown Format)'
    );
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => 'Ignored (Not a WHMCS order_id)']);
    exit;
}

$invoiceId = (int)$matches[1];

// Validasi keberadaan invoice di database WHMCS
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

// Tentukan identifier transaksi (ID invoice Aulaa atau order_id)
$transId = !empty($data['id']) ? (string)$data['id'] : $orderRef;
$amount  = isset($data['amount']) ? (float)$data['amount'] : 0.0;
$fee     = isset($data['fee_amount']) ? (float)$data['fee_amount'] : 0.0;

switch ($status) {
    case 'paid':
        // Cek idempoten: pastikan transaksi belum pernah dicatatkan sebelumnya
        checkCbTransID($transId);

        // Tambahkan pembayaran ke invoice WHMCS (otomatis mengubah status ke Paid jika lunas)
        addInvoicePayment(
            $invoiceId,
            $transId,
            $amount,
            $fee,
            $gatewayModuleName
        );

        // Catat ke Gateway Log WHMCS
        logTransaction(
            $gatewayParams['name'],
            $data,
            'Successful'
        );
        break;

    case 'expired':
    case 'cancelled':
        logTransaction(
            $gatewayParams['name'],
            $data,
            'Cancelled/Expired'
        );
        break;

    case 'failed':
        logTransaction(
            $gatewayParams['name'],
            $data,
            'Failed'
        );
        break;

    default:
        logTransaction(
            $gatewayParams['name'],
            $data,
            'Pending: ' . $status
        );
        break;
}

// Berikan respons HTTP 200 JSON ke server Aulaa
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'message' => 'ok']);
exit;
