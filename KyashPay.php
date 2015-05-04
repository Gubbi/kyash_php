<?php

class KyashPay {
    private static $baseUri = 'https://api.kyash.in/v1';
    public $key = '';
    public $secret = '';
    public $hmac = NULL;
    public $logger = NULL;

    public function __construct($key, $secret, $callback_secret = NULL, $hmac = NULL) {
        $this->key = $key;
        $this->secret = $secret;
        $this->callback_secret = $callback_secret;
        $this->hmac = $hmac;
    }

    public function createKyashCode($data) {
        return $this->api_request(self::$baseUri . '/kyashcodes/', $data);
    }

    public function getKyashCode($kyash_code) {
        return $this->api_request(self::$baseUri . '/kyashcodes/' . $kyash_code);
    }

    public function capture($kyash_code) {
        $url = self::$baseUri . '/kyashcodes/' . $kyash_code . '/capture';
        $params = "completion_expected_by=" . strtotime("+3 day");
        $params .= "&details=shipment completed";
        return $this->api_request($url, $params);
    }

    public function cancel($kyash_code, $reason='requested_by_customer') {
        $url = self::$baseUri . '/kyashcodes/' . $kyash_code . '/cancel';
        $params = "reason=".$reason;
        return $this->api_request($url, $params);
    }

    public function getPaymentPoints($pincode) {
        return $this->api_request(self::$baseUri . '/paymentpoints/' . $pincode);
    }

    public function callback_handler($order, $kyash_code, $kyash_status, $req_url) {
        $scheme = parse_url($req_url, PHP_URL_SCHEME);

        if ($scheme === 'https') {
            if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
                $this->log("Handler: Required header values missing.");
                header("HTTP/1.1 401 Unauthorized");
                return;
            }

            $httpd_username = filter_var($_SERVER['PHP_AUTH_USER'], FILTER_SANITIZE_STRING, FILTER_FLAG_ENCODE_HIGH | FILTER_FLAG_ENCODE_LOW);
            $httpd_password = filter_var($_SERVER['PHP_AUTH_PW'], FILTER_SANITIZE_STRING, FILTER_FLAG_ENCODE_HIGH | FILTER_FLAG_ENCODE_LOW);

            if ($httpd_username !== $this->key || $httpd_password !== $this->callback_secret) {
                $this->log("Handler: Required credentials not found.");
                header("HTTP/1.1 401 Unauthorized");
                return;
            }
        }
        else {
            $headers = getallheaders();
            $authorization = isset($headers['Authorization']) ? $headers['Authorization'] : '';

            if (empty($authorization)) {
                $this->log("Handler: HTTP/1.1 401 Unauthorized");
                header("HTTP/1.1 401 Unauthorized");
                return;
            }

            $normalized_request_string = '';
            foreach ($_REQUEST as $key => $value) {
                $normalized_request_string .= empty($normalized_request_string)? '' : '&';
                $normalized_request_string .= urlencode(utf8_encode($key) . '=' . utf8_encode($value));
            }

            //prepare request signature
            $request = urlencode('POST') . '&' . urlencode($req_url) . '&' . $normalized_request_string;
            $this->log('Normalized request string:' . $request);

            $signature = base64_encode(hash_hmac('sha256', $request, $this->hmac, true));
            $prepared_signature = "HMAC " . base64_encode($this->key . ":" . $signature);
            $this->log($authorization . '\n' . $prepared_signature);

            if ($authorization !== $prepared_signature) {
                $this->log("Handler: Signatures do not match.");
                header("HTTP/1.1 401 Unauthorized");
                return;
            }
        }

        $code = trim($_REQUEST['kyash_code']);
        $status = trim($_REQUEST['status']);
        $phone = trim($_REQUEST['paid_by']);

        if ($kyash_code !== $code) {
            $this->log("Handler: KyashCode not found");
            header("HTTP/1.1 404 Not Found");
            return;
        }

        if ($status === 'paid' && $kyash_status === 'pending') {
            $comment = "Customer(Ph: $phone) has made the payment via Kyash.";
            $order->update('paid', $comment);

            $this->log("Handler: Success - Paid");
        }
        else if ($status === 'expired' && $kyash_status === 'pending') {
            $comment = "This order was canceled since the KyashCode has expired.";
            $order->update('expired', $comment);

            $this->log("Handler: Success - Expired");
        }
        else {
            $this->log("Ignoring the callback as our status is " . $kyash_status . " and the event is for the status " . $status . ".");
        }
        exit;
    }

    public function setLogger($object) {
        $this->logger = $object;
    }

    public function api_request($url, $data = NULL) {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, TRUE);
        curl_setopt($curl, CURLOPT_CAINFO, dirname(__FILE__) . '/cacert.pem');
        curl_setopt($curl, CURLOPT_USERPWD, $this->key . ':' . $this->secret);
        if($data) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        $response = curl_exec($curl);

        if (curl_error($curl)) {
            $error = curl_error($curl);
            $response = array("status" => "error", "message" => $error);
        } else {
            $response = json_decode($response, true);
        }
        curl_close($curl);

        $this->log('Request: ' . $url . ' => ' . $data);
        $this->log('Response: ' . json_encode($response));
        return $response;
    }

    function log($msg) {
        if ($this->logger && is_object($this->logger) && method_exists($this->logger, 'write')) {
            $this->logger->write($msg);
        }
    }
}
?>