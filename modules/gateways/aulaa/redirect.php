<?php
/**
 * Handler Redirect Checkout Aulaa untuk WHMCS.
 *
 * Menerima permintaan bayar dari viewinvoice.php, memvalidasi otentikasi/token,
 * membuat invoice transaksi di Aulaa API, lalu mengarahkan browser pelanggan
 * ke halaman pembayaran hosted Aulaa (https://payment.aulaa.co/pay/{id}).
 *
 * @package    Aulaa_WHMCS
 * @author     Aulaa.co
 * @copyright  2026 Aulaa.co
 * @license    MIT
 */

require_once __DIR__ . '/../../../init.php';

use WHMCS\Database\Capsule;

App::load_function('gateway');
App::load_function('invoice');

$gatewayModuleName = 'aulaa';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(403);
    die("Modul pembayaran Aulaa belum diaktifkan di WHMCS.");
}

$invoiceId = (int)($_GET['invoiceid'] ?? $_POST['invoiceid'] ?? 0);
$token     = (string)($_GET['token'] ?? $_POST['token'] ?? '');

// Pastikan parameter invoice ID ada
if ($invoiceId <= 0) {
    http_response_code(400);
    die("Parameter ID Invoice tidak valid.");
}

// Ambil data invoice dari database WHMCS
$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice) {
    http_response_code(404);
    die("Invoice #" . htmlspecialchars($invoiceId) . " tidak ditemukan.");
}

// Jika invoice sudah lunas, langsung kembalikan ke viewinvoice
if (strtolower($invoice->status) === 'paid') {
    header("Location: " . $gatewayParams['systemurl'] . "viewinvoice.php?id=" . $invoiceId);
    exit;
}

// Validasi otentikasi: Pelanggan yang login ATAU memiliki valid signed HMAC token
$expectedToken = hash_hmac('sha256', $invoice->id . '|' . $invoice->total, (string)$gatewayParams['webhookSecret']);
$isUserOwner   = (isset($_SESSION['uid']) && (int)$_SESSION['uid'] === (int)$invoice->userid);
$isTokenValid  = (!empty($token) && hash_equals($expectedToken, $token));

if (!$isUserOwner && !$isTokenValid) {
    http_response_code(403);
    die("Akses ditolak: Verifikasi token otentikasi tidak sah.");
}

// Hitung sisa tagihan yang belum dibayar (balance due)
$paidAmount = (float)Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
$balanceDue = (float)$invoice->total - $paidAmount;
$amount     = (int)round($balanceDue);

if ($amount < 1) {
    header("Location: " . $gatewayParams['systemurl'] . "viewinvoice.php?id=" . $invoiceId);
    exit;
}

// Format order_id unik: whmcs-{invoiceId}-{randomHex}
// Token acak memastikan jika pembayaran sebelumnya expired/dibatalkan, retry menghasilkan
// invoice baru di Aulaa (tidak terkena HTTP 409 Conflict idempotency Aulaa).
$randomSuffix = bin2hex(random_bytes(4));
$orderRef     = 'whmcs-' . $invoiceId . '-' . $randomSuffix;

$systemUrl   = rtrim($gatewayParams['systemurl'], '/') . '/';
$redirectUrl = $systemUrl . 'viewinvoice.php?id=' . $invoiceId;

require_once __DIR__ . '/lib/AulaaApi.php';

try {
    $apiBase = !empty($gatewayParams['apiBase']) ? $gatewayParams['apiBase'] : 'https://api.aulaa.co/v1';
    $payBase = !empty($gatewayParams['payBase']) ? $gatewayParams['payBase'] : 'https://payment.aulaa.co';

    $api = new AulaaApi($apiBase, $gatewayParams['apiKey']);
    $res = $api->createPayment([
        'order_id'     => $orderRef,
        'amount'       => $amount,
        'redirect_url' => $redirectUrl,
    ]);

    $invoiceAulaaId = $res['id'] ?? '';
    if (empty($invoiceAulaaId)) {
        throw new Exception("Respons dari Aulaa tidak menyertakan ID transaksi.");
    }

    // Catat log pembuatan invoice
    logTransaction(
        $gatewayParams['name'],
        [
            'action'         => 'create_payment',
            'whmcs_invoice'  => $invoiceId,
            'aulaa_order_id' => $orderRef,
            'aulaa_id'       => $invoiceAulaaId,
            'amount'         => $amount,
        ],
        'Payment Initiated'
    );

    // Redirect pelanggan ke halaman pembayaran Aulaa
    $paymentUrl = rtrim($payBase, '/') . '/pay/' . rawurlencode($invoiceAulaaId);
    header("Location: " . $paymentUrl);
    exit;

} catch (Exception $e) {
    // Catat kegagalan ke Gateway Log WHMCS
    logTransaction(
        $gatewayParams['name'],
        [
            'action'        => 'create_payment_error',
            'whmcs_invoice' => $invoiceId,
            'order_ref'     => $orderRef,
            'error'         => $e->getMessage(),
        ],
        'Payment Creation Failed'
    );

    // Tampilkan halaman error yang rapi
    $errorMessage = htmlspecialchars($e->getMessage());
    $invoiceLink  = htmlspecialchars($redirectUrl);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Gagal Memulai Pembayaran Aulaa</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                background-color: #f3f4f6;
                color: #1f2937;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
                padding: 1rem;
            }
            .card {
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
                max-width: 480px;
                width: 100%;
                padding: 2rem;
                text-align: center;
                box-sizing: border-box;
            }
            .icon-wrapper {
                width: 56px;
                height: 56px;
                background-color: #fee2e2;
                color: #ef4444;
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 1.25rem;
            }
            .icon-wrapper svg {
                width: 28px;
                height: 28px;
            }
            h1 {
                font-size: 1.25rem;
                font-weight: 700;
                margin: 0 0 0.5rem 0;
                color: #111827;
            }
            p.desc {
                font-size: 0.925rem;
                color: #4b5563;
                margin: 0 0 1.25rem 0;
                line-height: 1.5;
            }
            .error-box {
                background-color: #fef2f2;
                border: 1px solid #fecaca;
                color: #991b1b;
                border-radius: 8px;
                padding: 0.75rem 1rem;
                font-size: 0.85rem;
                text-align: left;
                margin-bottom: 1.5rem;
                word-break: break-word;
            }
            .btn {
                display: inline-block;
                width: 100%;
                background-color: #2563eb;
                color: #ffffff;
                text-decoration: none;
                font-weight: 600;
                font-size: 0.95rem;
                padding: 0.75rem 1.25rem;
                border-radius: 8px;
                box-sizing: border-box;
                transition: background-color 0.15s ease;
            }
            .btn:hover {
                background-color: #1d4ed8;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon-wrapper">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h1>Gagal Memproses Pembayaran</h1>
            <p class="desc">Terjadi kendala saat menghubungkan tagihan Anda ke gerbang pembayaran Aulaa.</p>
            <div class="error-box">
                <strong>Detail Error:</strong><br>
                <?= $errorMessage ?>
            </div>
            <a href="<?= $invoiceLink ?>" class="btn">Kembali ke Invoice #<?= $invoiceId ?></a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
