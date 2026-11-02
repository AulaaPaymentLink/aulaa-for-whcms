<?php
/**
 * Aulaa Payment Link API Client for WHMCS.
 *
 * Aulaa adalah layanan *payment link*: transaksi yang dibuat lewat
 * `POST /v1/payments` mengembalikan field `payment_link` berisi URL halaman
 * pembayaran Aulaa, dan pelanggan diarahkan ke link tersebut.
 *
 * Sejak 30 Oktober 2026 field `payment_number` (nomor Virtual Account, string
 * QRIS, link e-wallet, dan kode bayar gerai retail) tidak lagi dikirim lewat
 * API dan digantikan sepenuhnya oleh `payment_link`.
 *
 * @package    Aulaa_WHMCS
 * @author     Aulaa.co
 * @copyright  2026 Aulaa.co
 * @license    MIT
 * @link       https://aulaa.co/dokumentasi/api-v1/apa-itu-api-call
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Exception API Aulaa yang turut membawa HTTP status code dan body response.
 *
 * Body diperlukan antara lain untuk membaca `payment_id` pada response
 * 409 Conflict (order_id sudah pernah dipakai).
 */
class AulaaApiException extends Exception
{
    /** @var int HTTP status code dari response Aulaa (0 bila gagal di level koneksi) */
    private $httpCode;

    /** @var array Body response yang sudah di-decode (kosong bila bukan JSON) */
    private $body;

    /**
     * @param string $message
     * @param int    $httpCode
     * @param array  $body
     */
    public function __construct($message, $httpCode = 0, array $body = [])
    {
        parent::__construct((string)$message, (int)$httpCode);
        $this->httpCode = (int)$httpCode;
        $this->body     = $body;
    }

    /**
     * @return int
     */
    public function getHttpCode()
    {
        return $this->httpCode;
    }

    /**
     * @return array
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * Ambil satu field dari body response.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function getBodyField($key, $default = null)
    {
        return array_key_exists($key, $this->body) ? $this->body[$key] : $default;
    }
}

class AulaaApi
{
    /** @var string Base URL API Aulaa (mis. https://api.aulaa.co/v1) */
    private $apiBase;

    /** @var string API Key project Aulaa (Bearer token, format pgw_id_...) */
    private $apiKey;

    /** @var string Versi plugin untuk header User-Agent */
    const VERSION = '1.1.0';

    /** @var int Nominal transaksi minimum yang diterima Aulaa (Rupiah penuh) */
    const MIN_AMOUNT = 1000;

    /** @var int Nominal transaksi maksimum yang diterima Aulaa (Rupiah penuh) */
    const MAX_AMOUNT = 500000000;

    /**
     * @param string $apiBase
     * @param string $apiKey
     */
    public function __construct($apiBase, $apiKey)
    {
        $this->apiBase = rtrim(trim((string)$apiBase), '/');
        $this->apiKey  = trim((string)$apiKey);
    }

    /**
     * Buat transaksi pembayaran baru di Aulaa.
     *
     * `order_id` bersifat unik per project. Mengirim ulang `order_id` yang sama
     * dijawab 409 Conflict (bukan membuat transaksi kedua), dan body response
     * 409 memuat `payment_id` transaksi yang sudah ada.
     *
     * @param array $params [
     *     'order_id'       => string (wajib),
     *     'amount'         => int    (wajib, Rupiah penuh),
     *     'redirect_url'   => string (opsional),
     *     'payment_method' => string (opsional, mis. 'qris' atau 'bca_va'),
     * ]
     * @return array Response Aulaa: id, order_id, status, amount, fee_amount,
     *               charged_amount, currency, country_code, payment_method,
     *               payment_link, redirect_url, is_test, expired_at, paid_at,
     *               created_at
     * @throws AulaaApiException
     */
    public function createPayment(array $params)
    {
        $orderId = isset($params['order_id']) ? trim((string)$params['order_id']) : '';
        if ($orderId === '') {
            throw new AulaaApiException('order_id wajib diisi untuk membuat pembayaran Aulaa.');
        }

        $amount = (int)(isset($params['amount']) ? $params['amount'] : 0);
        self::assertAmountInRange($amount);

        $body = [
            'order_id' => $orderId,
            'amount'   => $amount,
        ];

        // Kunci metode pembayaran bila diminta; bila dikosongkan, pelanggan
        // memilih sendiri metodenya di halaman pembayaran Aulaa.
        if (!empty($params['payment_method'])) {
            $body['payment_method'] = (string)$params['payment_method'];
        }

        if (!empty($params['redirect_url'])) {
            $body['redirect_url'] = (string)$params['redirect_url'];
        }

        return $this->request('POST', '/payments', $body);
    }

    /**
     * Ambil status terkini sebuah pembayaran berdasarkan id invoice Aulaa.
     *
     * Perhatikan: parameternya adalah field `id` dari response pembuatan
     * transaksi, bukan `order_id` milik sistem kita sendiri.
     *
     * @param string $paymentId Id invoice Aulaa (UUID)
     * @return array
     * @throws AulaaApiException
     */
    public function getPayment($paymentId)
    {
        $paymentId = trim((string)$paymentId);
        if ($paymentId === '') {
            throw new AulaaApiException('Id pembayaran Aulaa tidak boleh kosong.');
        }

        return $this->request('GET', '/payments/' . rawurlencode($paymentId));
    }

    /**
     * Batalkan pembayaran yang masih berstatus `pending`.
     *
     * Pembayaran yang sudah `paid`, `expired`, atau `cancelled` dijawab
     * 409 Conflict oleh Aulaa.
     *
     * @param string $paymentId Id invoice Aulaa (UUID)
     * @return array
     * @throws AulaaApiException
     */
    public function cancelPayment($paymentId)
    {
        $paymentId = trim((string)$paymentId);
        if ($paymentId === '') {
            throw new AulaaApiException('Id pembayaran Aulaa tidak boleh kosong.');
        }

        return $this->request('POST', '/payments/' . rawurlencode($paymentId) . '/cancel');
    }

    /**
     * Pastikan nominal berada dalam batas yang diterima Aulaa.
     *
     * @param int $amount
     * @return void
     * @throws AulaaApiException
     */
    public static function assertAmountInRange($amount)
    {
        $amount = (int)$amount;

        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            throw new AulaaApiException(sprintf(
                'Nominal tagihan Rp%s berada di luar batas yang diterima Aulaa (Rp%s sampai Rp%s).',
                number_format($amount, 0, ',', '.'),
                number_format(self::MIN_AMOUNT, 0, ',', '.'),
                number_format(self::MAX_AMOUNT, 0, ',', '.')
            ));
        }
    }

    /**
     * Ambil dan validasi `payment_link` dari response Aulaa.
     *
     * Link divalidasi agar modul tidak pernah mengarahkan pelanggan ke host
     * selain host pembayaran yang dikonfigurasi (perlindungan open redirect).
     *
     * @param array  $response     Response POST /payments atau GET /payments/{id}
     * @param string $expectedHost Host yang diizinkan (mis. payment.aulaa.co).
     *                             Kosongkan untuk melewati pemeriksaan host.
     * @return string URL halaman pembayaran Aulaa
     * @throws AulaaApiException
     */
    public static function extractPaymentLink(array $response, $expectedHost = '')
    {
        $link = isset($response['payment_link']) ? trim((string)$response['payment_link']) : '';
        if ($link === '') {
            throw new AulaaApiException(
                'Response Aulaa tidak menyertakan payment_link. Pastikan project Anda bertipe API ' .
                'dan modul ini sudah versi terbaru.'
            );
        }

        $parts = parse_url($link);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new AulaaApiException('payment_link dari Aulaa bukan URL yang valid.');
        }

        if (strtolower((string)$parts['scheme']) !== 'https') {
            throw new AulaaApiException('payment_link dari Aulaa wajib memakai skema HTTPS.');
        }

        $expectedHost = strtolower(trim((string)$expectedHost));
        $actualHost   = strtolower((string)$parts['host']);

        if ($expectedHost !== '' && $actualHost !== $expectedHost) {
            throw new AulaaApiException(sprintf(
                'Host payment_link (%s) tidak cocok dengan Payment Host yang dikonfigurasi (%s).',
                $actualHost,
                $expectedHost
            ));
        }

        return $link;
    }

    /**
     * Eksekusi HTTP request ke API Aulaa menggunakan cURL.
     *
     * @param string     $method GET atau POST
     * @param string     $path   Path endpoint, diawali '/'
     * @param array|null $body   Payload JSON (opsional)
     * @return array
     * @throws AulaaApiException
     */
    private function request($method, $path, ?array $body = null)
    {
        if ($this->apiKey === '') {
            throw new AulaaApiException(
                'API Key Aulaa belum dikonfigurasi di pengaturan Payment Gateway WHMCS.'
            );
        }

        $url = $this->apiBase . $path;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Aulaa-WHMCS/' . self::VERSION,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if (strtoupper((string)$method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body !== null ? $body : []));
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        }

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError !== '') {
            throw new AulaaApiException('Koneksi ke API Aulaa gagal (cURL): ' . $curlError);
        }

        $decoded = json_decode((string)$response, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($httpCode < 200 || $httpCode >= 300) {
            // Body error API v1 Aulaa berformat { "error": "pesan" }
            $errorMessage = 'Gagal menghubungi server Aulaa (HTTP ' . $httpCode . ')';
            if (!empty($decoded['error'])) {
                $errorMessage = (string)$decoded['error'];
            } elseif (!empty($decoded['message'])) {
                $errorMessage = (string)$decoded['message'];
            }

            throw new AulaaApiException($errorMessage, $httpCode, $decoded);
        }

        if ($decoded === [] || json_last_error() !== JSON_ERROR_NONE) {
            throw new AulaaApiException(
                'Format respons API Aulaa tidak valid (bukan JSON yang bisa dibaca).',
                $httpCode
            );
        }

        return $decoded;
    }
}
