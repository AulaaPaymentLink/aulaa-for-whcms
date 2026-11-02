<?php
/**
 * Callback/Webhook Listener Aulaa untuk WHMCS.
 *
 * Menerima HTTP POST notifikasi dari Aulaa, memvalidasi tanda tangan
 * HMAC-SHA256 dari raw body, lalu memperbarui status invoice WHMCS.
 *
 * Payload Aulaa bersifat flat (tanpa pembungkus `event`); jenis notifikasi
 * dibedakan dari field `status`. Payload webhook tidak memuat field `id`
 * maupun `fee_amount`, sehingga idempotensi disandarkan pada `order_id`
 * sesuai anjuran dokumentasi Aulaa.
 *
 * @package    Aulaa_WHMCS
 * @author     Aulaa.co
 * @copyright  2026 Aulaa.co
 * @license    MIT
 * @link       https://aulaa.co/dokumentasi/api-v1/webhook
 */

require_once __DIR__ . '/../../../init.php';

use WHMCS\Database\Capsule;

App::load_function('gateway');
App::load_function('invoice');

$gatewayModuleName = 'aulaa';
$gatewayParams = getGatewayVariables($gatewayModuleName);

/**
 * Jawab Aulaa dengan JSON lalu akhiri eksekusi.
 *
 * @param int    $httpCode
 * @param bool   $ok
 * @param string $message
 * @return void
 */
function aulaa_webhook_respond($httpCode, $ok, $message)
{
    http_response_code((int)$httpCode);
    header('Content-Type: application/json');
    echo json_encode(['ok' => (bool)$ok, 'message' => (string)$message]);
    exit;
}

if (!$gatewayParams['type']) {
    aulaa_webhook_respond(403, false, 'Modul pembayaran Aulaa belum diaktifkan di WHMCS.');
}

// Raw body wajib dipakai apa adanya untuk validasi signature
$rawBody = file_get_contents('php://input');

// Signature dikirim pada header X-Webhook-Signature (HMAC-SHA256 hex, tanpa prefix)
$signature = '';
if (isset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'])) {
    $signature = trim((string)$_SERVER['HTTP_X_WEBHOOK_SIGNATURE']);
} elseif (function_exists('getallheaders')) {
    foreach ((array)getallheaders() as $headerKey => $headerVal) {
        if (strcasecmp($headerKey, 'X-Webhook-Signature') === 0) {
            $signature = trim((string)$headerVal);
            break;
        }
    }
}

$webhookSecret = (string)($gatewayParams['webhookSecret'] ?? '');
if ($webhookSecret === '') {
    logTransaction(
        $gatewayParams['name'],
        ['error' => 'Webhook secret belum dikonfigurasi pada pengaturan modul WHMCS.'],
        'Configuration Error'
    );
    aulaa_webhook_respond(500, false, 'Webhook secret belum dikonfigurasi di WHMCS.');
}

// Verifikasi HMAC-SHA256 dengan perbandingan constant-time
$expectedSignature = hash_hmac('sha256', (string)$rawBody, $webhookSecret);
if ($signature === '' || !hash_equals($expectedSignature, $signature)) {
    logTransaction(
        $gatewayParams['name'],
        [
            'error'              => 'Tanda tangan webhook tidak valid',
            'received_signature' => $signature,
            'raw_body'           => $rawBody,
        ],
        'Invalid Signature'
    );
    aulaa_webhook_respond(401, false, 'Invalid signature');
}

$data = json_decode((string)$rawBody, true);
if (!is_array($data) || empty($data['order_id']) || empty($data['status'])) {
    logTransaction(
        $gatewayParams['name'],
        ['error' => 'Payload JSON tidak valid atau field wajib kosong', 'body' => $rawBody],
        'Invalid Payload'
    );
    aulaa_webhook_respond(400, false, 'Invalid payload format');
}

$orderRef = (string)$data['order_id'];
$status   = strtolower(trim((string)$data['status']));

// Petakan order_id kembali ke ID invoice WHMCS. Format yang dikenali:
//   whmcs-{invoiceId}            -> format stabil saat ini
//   whmcs-{invoiceId}-r{n}       -> percobaan ulang setelah transaksi lama mati
//   whmcs-{invoiceId}-{hex}      -> format lama modul versi 1.0.x (tetap diterima
//                                   agar transaksi yang masih berjalan saat upgrade
//                                   tidak kehilangan notifikasinya)
if (!preg_match('/^whmcs-(\d+)(?:-[0-9a-z]+)?$/i', $orderRef, $matches)) {
    logTransaction(
        $gatewayParams['name'],
        ['message' => 'Notifikasi diabaikan: Format order_id bukan milik modul ini', 'order_id' => $orderRef],
        'Ignored (Unknown Format)'
    );
    aulaa_webhook_respond(200, true, 'Ignored (Not a WHMCS order_id)');
}

$invoiceId = (int)$matches[1];

// Validasi keberadaan invoice di database WHMCS
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

// Payload webhook tidak memuat `id`, jadi order_id dipakai sebagai transaction id
// sekaligus kunci idempotensi (Aulaa mengirim ulang hingga 4 kali bila gagal).
$transId = $orderRef;
$amount  = isset($data['amount']) ? (float)$data['amount'] : 0.0;

// `fee_amount` tidak dikirim pada payload webhook; biaya layanan Aulaa
// dicatat terpisah di dashboard Aulaa, bukan dibebankan ke invoice WHMCS.
$fee = 0.0;

switch ($status) {
    case 'paid':
        // Idempoten: tolak bila transaksi ini sudah pernah dicatat
        checkCbTransID($transId);

        // Bandingkan nominal webhook dengan sisa tagihan WHMCS, sesuai anjuran
        // dokumentasi Aulaa untuk memverifikasi amount sebelum update order.
        $paidAlready = (float)Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
        $balanceDue  = round((float)Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total') - $paidAlready, 2);
        $logStatus   = 'Successful';

        if (abs($amount - $balanceDue) > 0.01) {
            $logStatus = 'Successful (Amount Mismatch)';
            logTransaction(
                $gatewayParams['name'],
                [
                    'warning'       => 'Nominal webhook berbeda dengan sisa tagihan WHMCS',
                    'order_id'      => $orderRef,
                    'whmcs_invoice' => $invoiceId,
                    'webhook_amount' => $amount,
                    'whmcs_balance'  => $balanceDue,
                    'note'          => 'Pembayaran tetap dicatat sesuai nominal yang diterima Aulaa. '
                                       . 'Periksa manual bila selisihnya tidak disengaja.',
                ],
                'Amount Mismatch'
            );
        }

        addInvoicePayment(
            $invoiceId,
            $transId,
            $amount,
            $fee,
            $gatewayModuleName
        );

        logTransaction($gatewayParams['name'], $data, $logStatus);
        break;

    case 'expired':
    case 'cancelled':
        logTransaction($gatewayParams['name'], $data, 'Cancelled/Expired');
        break;

    case 'failed':
        logTransaction($gatewayParams['name'], $data, 'Failed');
        break;

    case 'refunded':
        // Aulaa tidak membatalkan pembayaran WHMCS secara otomatis: pengembalian
        // dana perlu ditindak manual oleh admin agar jejak akuntansinya jelas.
        logTransaction(
            $gatewayParams['name'],
            $data + [
                'note' => 'Dana dikembalikan ke pelanggan di sisi Aulaa. Invoice WHMCS TIDAK diubah '
                          . 'otomatis — lakukan refund/kredit manual pada invoice #' . $invoiceId . '.',
            ],
            'Refunded (Needs Manual Action)'
        );
        break;

    case 'pending':
        logTransaction($gatewayParams['name'], $data, 'Pending');
        break;

    default:
        logTransaction($gatewayParams['name'], $data, 'Unhandled Status: ' . $status);
        break;
}

aulaa_webhook_respond(200, true, 'ok');
