<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use PrestaShop\PrestaShop\Adapter\Configuration;


include_once('components/SpectroCoin_Utilities.php');
include_once('data/SpectroCoin_ApiError.php');
include_once('data/SpectroCoin_OrderStatusEnum.php');
include_once('data/SpectroCoin_OrderCallback.php');
include_once('messages/SpectroCoin_CreateOrderRequest.php');
include_once('messages/SpectroCoin_CreateOrderResponse.php');


class SCMerchantClient
{

	private $merchant_api_url;
	private $project_id;
	private $client_id;
	private $client_secret;
	private $auth_url;
	
	private $access_token_data;
	private $encryption_key;
	private $access_token_config_key;
	private $public_spectrocoin_cert_location;
	protected $guzzle_client;
	protected $configuration;

	/**
	 * @param $merchant_api_url
	 * @param $project_id
	 * @param $client_id
	 * @param $client_secret
	 * @param $auth_url
	 */
	function __construct($merchant_api_url, $project_id, $client_id, $client_secret, $auth_url)
	{
		$this->merchant_api_url = $merchant_api_url;
		$this->project_id = $project_id;
		$this->client_id = $client_id;
		$this->client_secret = $client_secret;
		$this->auth_url = $auth_url;

		$this->guzzle_client = new Client();
		$this->encryption_key = hash('sha256', _COOKIE_KEY_);
		$this->access_token_config_key = 'SPECTROCOIN_ACCESS_TOKEN';
		$this->public_spectrocoin_cert_location = "https://test.spectrocoin.com/public.pem";// PROD:https://spectrocoin.com/files/merchant.public.pem
		$this->configuration = new Configuration();
	}

	/**
	 * Creates a new order with SpectroCoin and returns the order details or an error.
	 * This method first obtains an access token, then uses it to create an order with the provided request parameters.
	 * If successful, it returns a `SpectroCoin_CreateOrderResponse` object with the order details.
	 * In case of failure, it returns a `SpectroCoin_ApiError` object with the error details.
	 *
	 * @param SpectroCoin_CreateOrderRequest $request The order request parameters.
	 * @return SpectroCoin_ApiError|SpectroCoin_CreateOrderResponse The response object with order details or an error object.
	 * @throws GuzzleException If there's an error in the HTTP request.
	 */
	public function spectrocoin_create_order(SpectroCoin_CreateOrderRequest $request)
	{
		$this->access_token_data = $this->spectrocoin_get_access_token_data();

		if (!$this->access_token_data) {
			return new SpectroCoin_ApiError('AuthError', 'Failed to obtain access token');
		}

		$payload = array(
			"orderId" => $request->getOrderId(),
			"projectId" => $this->project_id,
			"description" => $request->getDescription(),
			"payAmount" => $request->getPayAmount(),
			"payCurrencyCode" => $request->getPayCurrencyCode(),
			"receiveAmount" => $request->getReceiveAmount(),
			"receiveCurrencyCode" => $request->getReceiveCurrencyCode(),
			'callbackUrl' => 'http://localhost.com',
			'successUrl' => 'http://localhost.com',
			'failureUrl' => 'http://localhost.com',
			'lang' => $request->getLang()
		);

		$sanitized_payload = $this->spectrocoin_sanitize_create_order_payload($payload);

		if (!$this->spectrocoin_validate_create_order_payload($sanitized_payload)) {
            return new SpectroCoin_ApiError(-1, 'Invalid order creation payload, payload: ' . json_encode($sanitized_payload));
		}
		$json_payload = json_encode($sanitized_payload);

        try {
            $response = $this->guzzle_client->request('POST', $this->merchant_api_url . '/merchants/orders/create', [
                RequestOptions::HEADERS => [
					'Content-Type' => 'application/json',
					'Authorization' => 'Bearer ' . $this->access_token_data['access_token']
			],
                RequestOptions::BODY => $json_payload
            ]);

            $status_code = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true); 

            if ($status_code == 200 && $body != null) {
                if (is_array($body) && count($body) > 0 && isset($body[0]->code)) {
                    return new SpectroCoin_ApiError($body[0]->code, $body[0]->message);
                } else {
					return new SpectroCoin_CreateOrderResponse(
						$body['depositAddress'],
						$body['memo'],
						$body['orderId'],
						$body['payAmount'],
						$body['payCurrencyCode'],
						$body['preOrderId'],
						$body['receiveAmount'],
						$body['receiveCurrencyCode'],
						$body['redirectUrl'],
						$body['validUntil']
					);
                }
            }
        } catch (GuzzleException $e) {
			return new SpectroCoin_ApiError($e->getCode(), $e->getMessage());
        }
        return new SpectroCoin_ApiError('Invalid Response', 'No valid response received.');
	}
	
	/**
     * Retrieves the current access token data from PrestaShop's configuration.
     * If the token is expired or not present, attempts to refresh it.
     * 
     * @return array|null The access token data array if valid or successfully refreshed, null otherwise.
     */
    private function spectrocoin_get_access_token_data()
    {
        $encryptedAccessTokenData = $this->configuration->get($this->access_token_config_key);
        if ($encryptedAccessTokenData) {
            $accessTokenData = json_decode(SpectroCoin_Utilities::spectrocoin_decrypt_auth_data($encryptedAccessTokenData, $this->encryption_key), true);
            $this->access_token_data = $accessTokenData;
            if ($this->spectrocoin_is_token_valid(time())) {
                return $this->access_token_data;
            }
        }
        return $this->spectrocoin_refresh_access_token(time());
    }

	/**
	 * Refreshes the access token by making a request to the SpectroCoin authorization server using client credentials. If successful, it updates the stored token data in WordPress transients.
	 * This method ensures that the application always has a valid token for authentication with SpectroCoin services.
	 *
	 * @param int $currentTime The current timestamp, used to calculate the new expiration time for the refreshed token.
	 * @return array|null Returns the new access token data if the refresh operation is successful. Returns null if the operation fails due to a network error or invalid response from the server.
	 * @throws GuzzleException Thrown if there is an error in the HTTP request to the SpectroCoin authorization server.
	 */
    private function spectrocoin_refresh_access_token($currentTime) {
        try {
            $response = $this->guzzle_client->post($this->auth_url, [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (!isset($data['access_token'], $data['expires_in'])) {
                return new SpectroCoin_ApiError('Invalid access token response', 'No valid response received.');
            }
            $data['expires_at'] = time() + $data['expires_in'];
            $this->access_token_data = $data;
			$encryptedAccessTokenData = SpectroCoin_Utilities::spectrocoin_encrypt_auth_data(json_encode($data), $this->encryption_key);
			$this->configuration->set($this->access_token_config_key, $encryptedAccessTokenData);
            return $this->access_token_data;
        } catch (GuzzleException $e) {
            return new SpectroCoin_ApiError('Failed to refresh access token', $e->getMessage());
        }
    }


	/**
	 * Checks if the current access token is valid by comparing the current time against the token's expiration time. A buffer can be applied to ensure the token is refreshed before it actually expires.
	 *
	 * @param int $currentTime The current timestamp, typically obtained using `time()`.
	 * @return bool Returns true if the token is valid (i.e., not expired), false otherwise.
	 */
	private function spectrocoin_is_token_valid($currentTime) {
		return isset($this->access_token_data['expires_at']) && $currentTime < $this->access_token_data['expires_at'];
	}

	// --------------- VALIDATION AND SANITIZATION BEFORE REQUEST -----------------

	/**
     * Payload data sanitization for create order
     * @param array $payload
     * @return array
     */
    private function spectrocoin_sanitize_create_order_payload($payload) {
		$sanitized_payload = [
			'orderId' => htmlspecialchars(trim($payload['orderId'])), // Removes any HTML tags and trims whitespace
			'projectId' => htmlspecialchars(trim($payload['projectId'])), // Removes any HTML tags and trims whitespace
			'description' => htmlspecialchars(trim($payload['description'])), // Removes any HTML tags and trims whitespace
			'payAmount' => filter_var($payload['payAmount'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION), // Sanitizes to a float
			'payCurrencyCode' => htmlspecialchars(trim($payload['payCurrencyCode'])), // Removes any HTML tags and trims whitespace
			'receiveAmount' => filter_var($payload['receiveAmount'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION), // Sanitizes to a float
			'receiveCurrencyCode' => htmlspecialchars(trim($payload['receiveCurrencyCode'])), // Removes any HTML tags and trims whitespace
			'callbackUrl' => filter_var($payload['callbackUrl'], FILTER_SANITIZE_URL), // Sanitizes URL
			'successUrl' => filter_var($payload['successUrl'], FILTER_SANITIZE_URL), // Sanitizes URL
			'failureUrl' => filter_var($payload['failureUrl'], FILTER_SANITIZE_URL), // Sanitizes URL
			'lang' => htmlspecialchars(trim($payload['lang'])) // Removes any HTML tags and trims whitespace
		];
		return $sanitized_payload;
	}

    /**
     * Payload data validation for create order
     * @param array $sanitized_payload
     * @return bool
     */
	private function spectrocoin_validate_create_order_payload($sanitized_payload) {
		return isset(
			$sanitized_payload['orderId'],
			$sanitized_payload['projectId'],
			$sanitized_payload['description'],
			$sanitized_payload['payAmount'],
			$sanitized_payload['payCurrencyCode'],
			$sanitized_payload['receiveAmount'],
			$sanitized_payload['receiveCurrencyCode'],
			$sanitized_payload['callbackUrl'],
			$sanitized_payload['successUrl'],
			$sanitized_payload['failureUrl'],
			$sanitized_payload['lang']
		) &&
		!empty($sanitized_payload['orderId']) &&
		!empty($sanitized_payload['projectId']) && 
		strlen($sanitized_payload['payCurrencyCode']) === 3 &&
		is_numeric($sanitized_payload['payAmount']) &&
		is_numeric($sanitized_payload['receiveAmount']) &&
		strlen($sanitized_payload['receiveCurrencyCode']) === 3 &&
		filter_var($sanitized_payload['callbackUrl'], FILTER_VALIDATE_URL) &&
		filter_var($sanitized_payload['successUrl'], FILTER_VALIDATE_URL) &&
		filter_var($sanitized_payload['failureUrl'], FILTER_VALIDATE_URL) &&
		!empty($sanitized_payload['lang']) &&
		($sanitized_payload['payAmount'] > 0 || $sanitized_payload['receiveAmount'] > 0);
	}
		
	// --------------- VALIDATION AND SANITIZATION AFTER CALLBACK -----------------

	/**
	 * @param $post_data
	 * @return SpectroCoin_OrderCallback|null
	 */
	public function spectrocoin_process_callback($post_data) {
		if ($post_data != null) {
			$sanitized_data = $this->spectrocoin_sanitize_callback($post_data);
			$isValid = $this->spectrocoin_validate_callback($sanitized_data);
			if ($isValid) {
				$order_callback = new SpectroCoin_OrderCallback($sanitized_data['userId'], $sanitized_data['merchantApiId'], $sanitized_data['merchantId'], $sanitized_data['apiId'], $sanitized_data['orderId'], $sanitized_data['payCurrency'], $sanitized_data['payAmount'], $sanitized_data['receiveCurrency'], $sanitized_data['receiveAmount'], $sanitized_data['receivedAmount'], $sanitized_data['description'], $sanitized_data['orderRequestId'], $sanitized_data['status'], $sanitized_data['sign']);
				if ($this->spectrocoin_validate_callback_payload($order_callback)) {
					return $order_callback;
				}
			}
			
		}
		return null;
	}

	/**
	 * Order callback data sanitization
	 * @param $post_data
	 * @return array
	 */
	public function spectrocoin_sanitize_callback($post_data) {
		return [
			'userId' => htmlspecialchars(trim($post_data['userId'])),
			'merchantApiId' => htmlspecialchars(trim($post_data['merchantApiId'])),
			'merchantId' => htmlspecialchars(trim($post_data['merchantId'])),
			'apiId' => htmlspecialchars(trim($post_data['apiId'])),
			'orderId' => htmlspecialchars(trim($post_data['orderId'])),
			'payCurrency' => htmlspecialchars(trim($post_data['payCurrency'])),
			'payAmount' => filter_var($post_data['payAmount'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
			'receiveCurrency' => htmlspecialchars(trim($post_data['receiveCurrency'])),
			'receiveAmount' => filter_var($post_data['receiveAmount'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
			'receivedAmount' => filter_var($post_data['receivedAmount'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
			'description' => htmlspecialchars(trim($post_data['description'])),
			'orderRequestId' => filter_var($post_data['orderRequestId'], FILTER_SANITIZE_NUMBER_INT),
			'status' => htmlspecialchars(trim($post_data['status'])),
			'sign' => htmlspecialchars(trim($post_data['sign'])),
		];
	}

	/**
	 * Order callback data validation
	 * @param $sanitized_data
	 * @return bool
	 */
	public function spectrocoin_validate_callback($sanitized_data) {
		$isValid = true;
		$failedFields = [];

		if (!isset(
            $sanitized_data['userId'], 
			$sanitized_data['merchantApiId'], 
            $sanitized_data['merchantId'], 
            $sanitized_data['apiId'],
			$sanitized_data['orderId'], 
			$sanitized_data['payCurrency'], 
			$sanitized_data['payAmount'], 
			$sanitized_data['receiveCurrency'], 
			$sanitized_data['receiveAmount'], 
			$sanitized_data['receivedAmount'], 
			$sanitized_data['description'], 
			$sanitized_data['orderRequestId'], 
			$sanitized_data['status'], 
			$sanitized_data['sign']
		)) {
			$isValid = false;
			$failedFields[] = 'One or more required fields are missing.';
		} else {
            if (empty($sanitized_data['userId'])) {
				$isValid = false;
				$failedFields[] = 'userId is empty.';
			}
			if (empty($sanitized_data['merchantApiId'])) {
				$isValid = false;
				$failedFields[] = 'merchantApiId is empty.';
			}
            if (empty($sanitized_data['merchantId'])) {
                $isValid = false;
                $failedFields[] = 'merchantId is empty.';
            }
            if (empty($sanitized_data['apiId'])) {
                $isValid = false;
                $failedFields[] = 'apiId is empty.';
            }
			if (strlen($sanitized_data['payCurrency']) !== 3) {
				$isValid = false;
				$failedFields[] = 'payCurrency is not 3 characters long.';
			}
			if (strlen($sanitized_data['receiveCurrency']) !== 3) {
				$isValid = false;
				$failedFields[] = 'receiveCurrency is not 3 characters long.';
			}
			if (!is_numeric($sanitized_data['payAmount']) || $sanitized_data['payAmount'] <= 0) {
				$isValid = false;
				$failedFields[] = 'payAmount is not a valid positive number.';
			}
			if (!is_numeric($sanitized_data['receiveAmount']) || $sanitized_data['receiveAmount'] <= 0) {
				$isValid = false;
				$failedFields[] = 'receiveAmount is not a valid positive number.';
			}
			if ($sanitized_data['status'] == 6) {
				if (!is_numeric($sanitized_data['receivedAmount'])) {
					$isValid = false;
					$failedFields[] = 'receivedAmount is not a valid number.';
				}
			} else {
				if (!is_numeric($sanitized_data['receivedAmount']) || $sanitized_data['receivedAmount'] < 0) {
					$isValid = false;
					$failedFields[] = 'receivedAmount is not a valid non-negative number.';
				}
			}
			if (!is_numeric($sanitized_data['orderRequestId']) || $sanitized_data['orderRequestId'] <= 0) {
				$isValid = false;
				$failedFields[] = 'orderRequestId is not a valid positive number.';
			}
			if (!is_numeric($sanitized_data['status']) || $sanitized_data['status'] <= 0) {
				$isValid = false;
				$failedFields[] = 'status is not a valid positive number.';
			}
		}

		if (!$isValid) {
			error_log('SpectroCoin error: Callback validation failed fields: ' . implode(', ', $failedFields));
		}
		return $isValid;
	}

	/**
	 * Order callback payload validation
	 * @param SpectroCoin_OrderCallback $order_callback
	 * @return bool
	 */
	public function spectrocoin_validate_callback_payload(SpectroCoin_OrderCallback $order_callback)
	{
		if ($order_callback != null) {

			$payload = array(
				'merchantId' => $order_callback->getMerchantId(),
				'apiId' => $order_callback->getApiId(),
				'orderId' => $order_callback->getOrderId(),
				'payCurrency' => $order_callback->getPayCurrency(),
				'payAmount' => $order_callback->getPayAmount(),
				'receiveCurrency' => $order_callback->getReceiveCurrency(),
				'receiveAmount' => $order_callback->getReceiveAmount(),
				'receivedAmount' => $order_callback->getReceivedAmount(),
				'description' => $order_callback->getDescription(),
				'orderRequestId' => $order_callback->getOrderRequestId(),
				'status' => $order_callback->getStatus(),
			);
			
			$data = http_build_query($payload);
            if ($this->spectrocoin_validate_signature($data, $order_callback->getSign()) == 1) {
				return true;
			} else {
				error_log('SpectroCoin Error: Signature validation failed');
			}
		}

		return false;
	}


	/**
	 * @param $data
	 * @param $signature
	 * @return int
	 */
	private function spectrocoin_validate_signature($data, $signature)
	{
		$sig = base64_decode($signature);
		$publicKey = file_get_contents($this->public_spectrocoin_cert_location);
		$public_key_pem = openssl_pkey_get_public($publicKey);
		$r = openssl_verify($data, $sig, $public_key_pem, OPENSSL_ALGO_SHA1);
		return $r;
	}

}