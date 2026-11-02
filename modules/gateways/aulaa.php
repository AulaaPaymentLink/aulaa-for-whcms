<?php
/**
 * Modul Payment Gateway Aulaa untuk WHMCS.
 *
 * Mengintegrasikan pembayaran serbaguna Aulaa (QRIS, Virtual Account, E-Wallet,
 * dan Gerai Retail) ke dalam sistem penagihan WHMCS.
 *
 * @package    Aulaa_WHMCS
 * @author     Aulaa.co
 * @copyright  2026 Aulaa.co
 * @license    MIT
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Config\Setting;

/**
 * Mendefinisikan metadata modul gateway WHMCS.
 *
 * @return array
 */
function aulaa_MetaData()
{
    return [
        'DisplayName'                 => 'Aulaa Payment Gateway',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

/**
 * Mendefinisikan field konfigurasi di halaman Admin WHMCS:
 * Setup -> Payments -> Payment Gateways.
 *
 * @return array
 */
function aulaa_config()
{
    $systemUrl   = Setting::getValue('SystemURL') ?: '';
    $callbackUrl = rtrim($systemUrl, '/') . '/modules/gateways/callback/aulaa.php';

    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Aulaa (QRIS, Virtual Account, E-Wallet)',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key',
            'Type'         => 'password',
            'Size'         => '50',
            'Default'      => '',
            'Description'  => 'API Key project Aulaa Anda (misal: <code>pgw_id_...</code>). Ambil dari Dashboard Aulaa &rarr; Project Settings.',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Secret',
            'Type'         => 'password',
            'Size'         => '50',
            'Default'      => '',
            'Description'  => 'Webhook Secret project Aulaa. Digunakan untuk memverifikasi keaslian signature HMAC-SHA256 dari webhook notifikasi.',
        ],
        'apiBase' => [
            'FriendlyName' => 'API Base URL',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => 'https://api.aulaa.co/v1',
            'Description'  => 'Default: <code>https://api.aulaa.co/v1</code>. Jangan ubah kecuali menggunakan lingkungan staging.',
        ],
        'payBase' => [
            'FriendlyName' => 'Payment Page URL',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => 'https://payment.aulaa.co',
            'Description'  => 'Default: <code>https://payment.aulaa.co</code>. Base URL halaman pembayaran yang dikunjungi pelanggan.',
        ],
        'autoRedirect' => [
            'FriendlyName' => 'Otomatis Alihkan (Auto Redirect)',
            'Type'         => 'yesno',
            'Description'  => 'Centang untuk langsung mengarahkan pelanggan ke halaman pembayaran Aulaa saat invoice dibuka.',
        ],
        'instructions' => [
            'FriendlyName' => 'Konfigurasi Webhook di Aulaa',
            'Type'         => 'textarea',
            'Rows'         => '3',
            'Description'  => 'Salin URL Callback berikut dan tempelkan pada kolom <strong>Callback URL</strong> di dashboard Aulaa:<br><code style="font-size:13px; color:#2563eb; user-select:all;">' . htmlspecialchars($callbackUrl) . '</code>',
        ],
    ];
}

/**
 * Menghasilkan HTML tombol/form pembayaran pada halaman tagihan klien (viewinvoice.php).
 *
 * @param array $params Parameter invoice dan konfigurasi gateway yang disediakan WHMCS
 * @return string HTML formulir pembayaran
 */
function aulaa_link($params)
{
    // Validasi mata uang: Aulaa hanya mendukung transaksi Rupiah (IDR)
    $currency = strtoupper((string)($params['currency'] ?? ''));
    if ($currency !== 'IDR') {
        return '<div class="alert alert-warning" style="margin:10px 0; text-align:left; font-size:13px;">' .
            '<strong>Peringatan Metode Pembayaran Aulaa:</strong><br>' .
            'Aulaa saat ini hanya menerima transaksi dalam mata uang <strong>IDR (Rupiah)</strong>.<br>' .
            'Invoice ini terbit dalam mata uang <code>' . htmlspecialchars($currency) . '</code>. Silakan pilih metode pembayaran lain.' .
            '</div>';
    }

    $invoiceId     = (int)$params['invoiceid'];
    $amount        = $params['amount'];
    $webhookSecret = (string)($params['webhookSecret'] ?? '');
    $systemUrl     = rtrim($params['systemurl'], '/') . '/';
    $redirectUrl   = $systemUrl . 'modules/gateways/aulaa/redirect.php';
    $autoRedirect  = !empty($params['autoRedirect']);

    // Buat tanda tangan HMAC untuk mengamankan link checkout dari manipulasi ID invoice
    $token = hash_hmac('sha256', $invoiceId . '|' . $amount, $webhookSecret);

    $html = '<div class="aulaa-payment-box" style="margin: 10px 0; text-align: center;">';
    $html .= '<form id="aulaa-pay-form" method="POST" action="' . htmlspecialchars($redirectUrl) . '">';
    $html .= '<input type="hidden" name="invoiceid" value="' . $invoiceId . '" />';
    $html .= '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '" />';

    // Tombol Bayar dengan gaya modern Aulaa
    $html .= '<button type="submit" class="btn btn-primary btn-lg" style="' .
        'background-color: #2563eb; border-color: #1d4ed8; color: #ffffff; ' .
        'padding: 10px 22px; font-weight: 600; border-radius: 8px; font-size: 15px; ' .
        'display: inline-flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer; ' .
        'box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2); transition: all 0.2s ease;">' .
        '<svg width="22" height="22" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle;">' .
        '<rect width="48" height="48" rx="10" fill="#111827"/>' .
        '<path d="M13 16l7 8-7 8" stroke="#3B82F6" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>' .
        '<path d="M22 16l7 8-7 8" stroke="#3B82F6" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.55"/>' .
        '</svg>' .
        '<span>Bayar Sekarang via Aulaa</span>' .
        '</button>';

    $html .= '<div style="margin-top: 8px; font-size: 12px; color: #6b7280;">' .
        'QRIS &bull; Virtual Account &bull; E-Wallet' .
        '</div>';

    $html .= '</form>';

    // Fitur auto-redirect jika diaktifkan di admin
    if ($autoRedirect) {
        $html .= '<div id="aulaa-redirect-notice" style="margin-top: 10px; font-size: 13px; color: #4b5563;">' .
            '<div class="spinner-border spinner-border-sm text-primary" role="status" style="margin-right: 5px; width: 14px; height: 14px; border-width: 2px;"></div>' .
            'Mengarahkan otomatis ke sistem pembayaran Aulaa... ' .
            '<small style="display:block; color:#9ca3af; margin-top:2px;">(Jika tidak beralih otomatis dalam beberapa detik, klik tombol di atas)</small>' .
            '</div>';
        $html .= '<script type="text/javascript">
            (function() {
                var aulaaRedirectTimer = setTimeout(function() {
                    var f = document.getElementById("aulaa-pay-form");
                    if (f) {
                        f.submit();
                    }
                }, 800);
            })();
        </script>';
    }

    $html .= '</div>';

    return $html;
}
