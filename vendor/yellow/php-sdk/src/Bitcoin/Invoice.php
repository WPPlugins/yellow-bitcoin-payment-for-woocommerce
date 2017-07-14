<?php
namespace Yellow\Bitcoin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Exception ;

class Invoice implements InvoiceInterface
{
    /**
     * sdk version
     */
    const VERSION = "0.1";

    /**
     * Server Root for Yellow API
     *
     * @var String
     */
    private $server_root = "https://api.yellowpay.co/v1";

    /**
     * create invoice URI
     *
     * @var String
     */
    private $api_uri_create_invoice = "/invoice/";

    /**
     * check invoice status URI
     *
     * @var String
     */
    private $api_uri_check_payment = "/invoice/[id]/";

    /**
     * api key
     *
     * @var String
     */
    private $api_key;

    /**
     * api secret
     *
     * @var String
     */
    private $api_secret;

    /**
     * constructor method
     * @note : if we want to use custom API server , we will read it automatically from EVN var
     * @param $api_key => public key
     * @param $api_secret => private key
     */
    public function __construct($api_key, $api_secret)
    {
        /// set custom API server
        $custom_server_root    = getenv("YELLOW_API_SERVER");
        if ($custom_server_root) {
            $this->server_root = $custom_server_root;
        }
        $this->api_key    = $api_key;
        $this->api_secret = $api_secret;
        return $this;
    }

    /**
     *
     * @param $payload array for all api parameters like: amount, currency, callback ... etc.
     * @return \GuzzleHttp\Message\ResponseInterface|mixed|null
     */
    public function createInvoice($payload = array())
    {
        try {
            $url      = $this->server_root . $this->api_uri_create_invoice;
            $data     = $this->createHTTPData($url , $payload);
            $client   = new Client();
            $response = $client->post($url, $data);
            $body     = (string) $response->getBody();
            return json_decode($body , true);
        } catch (ClientException $e) {
            if ($e->hasResponse()) {
                return $e->getResponse();
            }
        } catch (Exception $e){
            return $e->getTraceAsString();
        }
    }

    /**
     * check invoice status
     * @param $id
     * @return \GuzzleHttp\Message\ResponseInterface|mixed|null
     */
    public function checkInvoiceStatus($id)
    {
        try {
            $url      = $this->server_root . str_replace("[id]", $id, $this->api_uri_check_payment);
            $data     = $this->createHTTPData($url , array());
            $client   = new Client();
            $response = $client->get($url, $data);
            $body     = (string) $response->getBody();
            return json_decode($body , true);
        } catch (ClientException $e) {
            if ($e->hasResponse()) {
                return $e->getResponse();
            }
        } catch (Exception $e){
            return $e->getTraceAsString();
        }
    }

    /**
     * Validate IPN
     *
     * @param $url string
     * @param $signature string
     * @param $nonce string
     * @param $key string | public key that sent with IPN headers
     * @return bool
     */
    public function verifyIPN($url, $signature, $key, $nonce, $body)
    {
        if (!$key || !$nonce || !$signature || $body == "") {
            //// missing headers OR an empty payload
            return false;
        }
        $message = $nonce . $url . $body;
        $calculated_signature = $this->signMessage($message);
        if($calculated_signature <> $signature ){
            //// invalid IPN call
            return false;
        }
        //// valid IPN call
        return true;
    }


    /**
     * easily validate IPN
     * @return bool
     */
    public function easyVerifyIPN()
    {
        $url       = $this->getCurrentUrl();
        $signature = $_SERVER["HTTP_API_SIGN"];
        $api_key   = $_SERVER["HTTP_API_KEY"];
        $nonce     = $_SERVER["HTTP_API_NONCE"];
        $payload   =  file_get_contents("php://input"); //// raw post body
        /// or we could use
        /// $payload   =  stream_get_contents(STDIN);

        if (!$api_key || !$nonce || !$signature || $payload == "") {
            //// missing headers OR an empty payload
            return false;
        }

        $message = $nonce . $url . $payload;
        $calculated_signature = $this->signMessage($message);
        if($calculated_signature <> $signature ){
            //// invalid IPN call
            return false;
        }
        //// valid IPN call
        return true;

    }

    /**
     * get OS / PHP version
     *
     * @return string
     */
    private function getOSVersion()
    {
        return PHP_OS . " - PHP " . PHP_VERSION;
    }

    /**
     * creates the http data array for both createInvoice / checkInvoiceStatus
     *
     * @param $url url used to create signature
     * @param array $payload payload array
     * @return array
     */
    private function createHTTPData($url , $payload = array() )
    {
        $nonce = round(microtime(true) * 1000);
        if(!empty($payload)){
            $body        = json_encode($payload);
            $message     = $nonce . $url . $body;
            $append_body = true;
        }else{
            $message     = $nonce . $url;
            $append_body = false;
        }
        $signature = $this->signMessage($message);
        $data = [
            'headers'          => [
                'API-Key'      => $this->api_key,
                'API-Nonce'    => $nonce,
                'API-Sign'     => $signature,
                'API-Platform' => $this->getOSVersion(),
                'API-Plugin'   => self::VERSION,
                'content-type' => 'application/json'
            ],
            'allow_redirects'  => false,
            'timeout'          => 300
        ];
        if($append_body){
            $data["body"] = $body;
        }
        return $data;
    }

    /**
     * sign message using hash_hmac
     * @param $message string
     * @return string
     */
    private function signMessage($message)
    {
        return hash_hmac("sha256", $message, $this->api_secret, false);
    }

    /**
     * get current url
     * @return mixed
     */
    public function getCurrentUrl()
    {
        $s = &$_SERVER;
        $ssl = (!empty($s['HTTPS']) && $s['HTTPS'] == 'on') ? true:false;
        $sp = strtolower($s['SERVER_PROTOCOL']);
        $protocol = substr($sp, 0, strpos($sp, '/')) . (($ssl) ? 's' : '');
        $port = $s['SERVER_PORT'];
        $port = ((!$ssl && $port=='80') || ($ssl && $port=='443')) ? '' : ':'.$port;
        $host = isset($s['HTTP_X_FORWARDED_HOST']) ? $s['HTTP_X_FORWARDED_HOST'] : (isset($s['HTTP_HOST']) ? $s['HTTP_HOST'] : null);
        $host = isset($host) ? $host : $s['SERVER_NAME'] . $port;
        $uri = $protocol . '://' . $host . $s['REQUEST_URI'];
        $segments = explode('?', $uri, 2);
        $url = $segments[0];
        return $url;
    }

}
