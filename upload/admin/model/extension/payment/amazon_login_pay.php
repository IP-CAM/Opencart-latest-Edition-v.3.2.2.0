<?php
/**
 * Class Amazon Login Pay
 *
 * @package Admin\Model\Extension\Payment
 */
class ModelExtensionPaymentAmazonLoginPay extends Model {
	/**
	 * Install
	 *
	 * @return void
	 */
	public function install(): void {
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "amazon_login_pay_order` (
				`amazon_login_pay_order_id` int(11) NOT NULL AUTO_INCREMENT,
				`order_id` int(11) NOT NULL,
				`amazon_order_reference_id` varchar(255) NOT NULL,
				`amazon_authorization_id` varchar(255) NOT NULL,
				`free_shipping`  tinyint NOT NULL DEFAULT 0,
				`date_added` datetime NOT NULL,
				`modified` datetime NOT NULL,
				`capture_status` int(1) NOT NULL DEFAULT '0',
				`cancel_status` int(1) NOT NULL DEFAULT '0',
				`refund_status` int(1) NOT NULL DEFAULT '0',
				`currency_code` int(3) NOT NULL,
				`total` decimal(15,4) NOT NULL,
				KEY `amazon_order_reference_id` (`amazon_order_reference_id`),
				PRIMARY KEY `amazon_login_pay_order_id` (`amazon_login_pay_order_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		");

		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "amazon_login_pay_order_transaction` (
			  `amazon_login_pay_order_transaction_id` int(11) NOT NULL AUTO_INCREMENT,
			  `amazon_login_pay_order_id` int(11) NOT NULL,
			  `amazon_authorization_id` varchar(255),
			  `amazon_capture_id` varchar(255),
			  `amazon_refund_id` varchar(255),
			  `date_added` datetime NOT NULL,
			  `type` enum(\\'authorization\\',\\'capture\\',\\'refund\\',\\'cancel\\') DEFAULT NULL,
			  `status` enum(\\'Open\\',\\'Pending\\',\\'Completed\\',\\'Suspended\\',\\'Declined\\',\\'Closed\\',\\'Canceled\\') DEFAULT NULL,
			  `amount` decimal(15,4) NOT NULL,
			  PRIMARY KEY (`amazon_login_pay_order_transaction_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
			");
	}

	/**
	 * Uninstall
	 *
	 * @return void
	 */
	public function uninstall(): void {
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "amazon_login_pay_order`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "amazon_login_pay_order_total_tax`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "amazon_login_pay_order_transaction`");
	}

	/**
	 * Delete Events
	 *
	 * @return void
	 */
	public function deleteEvents(): void {
		// Events
		$this->load->model('setting/event');

		$this->model_setting_event->deleteEventByCode('amazon_edit_capture');
		$this->model_setting_event->deleteEventByCode('amazon_history_capture');
	}

	/**
	 * Add Events
	 *
	 * @return void
	 */
	public function addEvents(): void {
		// Events
		$this->load->model('setting/event');

		$this->model_setting_event->addEvent('amazon_edit_capture', 'catalog/model/checkout/order/editOrder/after', 'extension/payment/amazon_login_pay/capture');
		$this->model_setting_event->addEvent('amazon_history_capture', 'catalog/model/checkout/order/addHistory/after', 'extension/payment/amazon_login_pay/capture');
	}

	/**
	 * Get Order
	 *
	 * @param int $order_id
	 *
	 * @return array<string, mixed>
	 */
	public function getOrder(int $order_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "amazon_login_pay_order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");

		if ($query->num_rows) {
			$order = $query->row;
			$order['transactions'] = $this->getTransactions($order['amazon_login_pay_order_id'], $query->row['currency_code']);

			return $order;
		} else {
			return [];
		}
	}

	/**
	 * Cancel
	 *
	 * @param array<string, mixed> $amazon_login_pay_order
	 *
	 * @return array<string, mixed>
	 */
	public function cancel(array $amazon_login_pay_order): array {
		$total_captured = $this->getTotalCaptured($amazon_login_pay_order['amazon_login_pay_order_id']);

		if ($amazon_login_pay_order && $total_captured == 0) {
			$cancel_parameter_data = [];

			$cancel_parameter_data['AmazonOrderReferenceId'] = $amazon_login_pay_order['amazon_order_reference_id'];
			$cancel_details = $this->offAmazon('CancelOrderReference', $cancel_parameter_data);
			$cancel_details_xml = simplexml_load_string($cancel_details['ResponseBody']);

			$this->logger($cancel_details_xml);

			$cancel_response = [];

			if (isset($cancel_details_xml->Error)) {
				$cancel_response['status'] = 'Error';
				$cancel_response['status_detail'] = (string)$cancel_details_xml->Error->Code . ': ' . (string)$cancel_details_xml->Error->Message;
			} else {
				$cancel_response['status'] = 'Completed';
			}

			return $cancel_response;
		} else {
			return [];
		}
	}

	/**
	 * Update Cancel Status
	 *
	 * @param int $amazon_login_pay_order_id
	 * @param int $status
	 *
	 * @return void
	 */
	public function updateCancelStatus(int $amazon_login_pay_order_id, int $status): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "amazon_login_pay_order` SET `cancel_status` = '" . (int)$status . "' WHERE `amazon_login_pay_order_id` = '" . (int)$amazon_login_pay_order_id . "'");
	}

	/**
	 * Has Open Authorization
	 *
	 * @param array<string, mixed> $transactions
	 *
	 * @return bool
	 */
	public function hasOpenAuthorization(array $transactions): bool {
		foreach ($transactions as $transaction) {
			if ($transaction['type'] == 'authorization' && $transaction['status'] == 'Open') {
				return true;
			}
		}

		return false;
	}

	/**
	 * Capture
	 *
	 * @param array<string, mixed> $amazon_login_pay_order
	 * @param float                $amount
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function capture(array $amazon_login_pay_order, float $amount): array {
		$total_captured = $this->getTotalCaptured($amazon_login_pay_order['amazon_login_pay_order_id']);

		if ($amazon_login_pay_order && $amazon_login_pay_order['capture_status'] == 0 && ($total_captured + $amount <= $amazon_login_pay_order['total'])) {
			if (!$this->hasOpenAuthorization($amazon_login_pay_order['transactions'])) {
				$amazon_authorization = $this->authorize($amazon_login_pay_order, $amount);

				if (isset($amazon_authorization['AmazonAuthorizationId'])) {
					$amazon_authorization_id = $amazon_authorization['AmazonAuthorizationId'];
				} else {
					return $amazon_authorization;
				}
			} else {
				$amazon_authorization_id = $amazon_login_pay_order['amazon_authorization_id'];
			}

			$capture_parameter_data = [];

			$capture_parameter_data['TransactionTimeout'] = 0;
			$capture_parameter_data['AmazonOrderReferenceId'] = $amazon_login_pay_order['amazon_order_reference_id'];
			$capture_parameter_data['AmazonAuthorizationId'] = $amazon_authorization_id;
			$capture_parameter_data['CaptureAmount.Amount'] = $amount;
			$capture_parameter_data['CaptureAmount.CurrencyCode'] = $amazon_login_pay_order['currency_code'];
			$capture_parameter_data['CaptureReferenceId'] = 'capture_' . mt_rand();

			$capture_details = $this->offAmazon('Capture', $capture_parameter_data);

			$capture_response = $this->validateResponse('Capture', $capture_details);
			$capture_response['AmazonAuthorizationId'] = $amazon_authorization_id;

			return $capture_response;
		} else {
			return [];
		}
	}

	/**
	 * Authorize
	 *
	 * @param array<string, mixed> $amazon_login_pay_order
	 * @param float                $amount
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function authorize(array $amazon_login_pay_order, float $amount): array {
		$authorize_parameter_data = [];

		$authorize_parameter_data['TransactionTimeout'] = 0;
		$authorize_parameter_data['AmazonOrderReferenceId'] = $amazon_login_pay_order['amazon_order_reference_id'];
		$authorize_parameter_data['AuthorizationAmount.Amount'] = $amount;
		$authorize_parameter_data['AuthorizationAmount.CurrencyCode'] = $amazon_login_pay_order['currency_code'];
		$authorize_parameter_data['AuthorizationReferenceId'] = 'auth_' . mt_rand();

		$authorize_details = $this->offAmazon('Authorize', $authorize_parameter_data);

		return $this->validateResponse('Authorize', $authorize_details);
	}

	/**
	 * Close Order Ref
	 *
	 * @param mixed $amazon_order_reference_id
	 *
	 * @return void
	 */
	public function closeOrderRef($amazon_order_reference_id): void {
		$close_parameter_data = [];

		$close_parameter_data['AmazonOrderReferenceId'] = $amazon_order_reference_id;

		$this->offAmazon('CloseOrderReference', $close_parameter_data);

		$close_details = $this->offAmazon('CloseOrderReference', $close_parameter_data);

		$this->logger($close_details);
	}

	/**
	 * Update Capture Status
	 *
	 * @param int $amazon_login_pay_order_id
	 * @param int $status
	 *
	 * @return void
	 */
	public function updateCaptureStatus(int $amazon_login_pay_order_id, int $status): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "amazon_login_pay_order` SET `capture_status` = '" . (int)$status . "' WHERE `amazon_login_pay_order_id` = '" . (int)$amazon_login_pay_order_id . "'");
	}

	/**
	 * Refund
	 *
	 * @param array<string, mixed> $amazon_login_pay_order
	 * @param float                $amount
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function refund(array $amazon_login_pay_order, float $amount): array {
		if ($amazon_login_pay_order && $amazon_login_pay_order['refund_status'] != 1) {
			$amazon_captures_remaining = $this->getUncaptured($amazon_login_pay_order['amazon_login_pay_order_id']);

			$refund_response = [];

			$count = count($amazon_captures_remaining);

			$i = 0;

			for ($amount; $amount > 0 && $count > $i; $amount -= $amazon_captures_remaining[$i++]['capture_remaining']) {
				$refund_amount = $amount;

				if ($amazon_captures_remaining[$i]['capture_remaining'] <= $amount) {
					$refund_amount = $amazon_captures_remaining[$i]['capture_remaining'];
				}

				$refund_parameter_data = [];

				$refund_parameter_data['TransactionTimeout'] = 0;
				$refund_parameter_data['AmazonOrderReferenceId'] = $amazon_login_pay_order['amazon_order_reference_id'];
				$refund_parameter_data['AmazonCaptureId'] = $amazon_captures_remaining[$i]['amazon_capture_id'];
				$refund_parameter_data['RefundAmount.Amount'] = $refund_amount;
				$refund_parameter_data['RefundAmount.CurrencyCode'] = $amazon_login_pay_order['currency_code'];
				$refund_parameter_data['RefundReferenceId'] = 'refund_' . mt_rand();
				$refund_details = $this->offAmazon('Refund', $refund_parameter_data);
				$refund_response[$i] = $this->validateResponse('Refund', $refund_details);
				$refund_response[$i]['amazon_authorization_id'] = $amazon_captures_remaining[$i]['amazon_authorization_id'];
				$refund_response[$i]['amazon_capture_id'] = $amazon_captures_remaining[$i]['amazon_capture_id'];
				$refund_response[$i]['amount'] = $refund_amount;
			}

			return $refund_response;
		} else {
			return [];
		}
	}

	/**
	 * Get Uncaptured
	 *
	 * @param int $amazon_login_pay_order_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getUncaptured(int $amazon_login_pay_order_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "amazon_login_pay_order_transaction` WHERE (`type` = 'refund' OR `type` = 'capture') AND `amazon_login_pay_order_id` = '" . (int)$amazon_login_pay_order_id . "' ORDER BY `date_added`");

		$uncaptured = [];

		foreach ($query->rows as $row) {
			$uncaptured[$row['amazon_capture_id']]['amazon_authorization_id'] = $row['amazon_authorization_id'];
			$uncaptured[$row['amazon_capture_id']]['amazon_capture_id'] = $row['amazon_capture_id'];

			if (isset($uncaptured[$row['amazon_capture_id']]['capture_remaining'])) {
				$uncaptured[$row['amazon_capture_id']]['capture_remaining'] += $row['amount'];
			} else {
				$uncaptured[$row['amazon_capture_id']]['capture_remaining'] = $row['amount'];
			}

			if ($uncaptured[$row['amazon_capture_id']]['capture_remaining'] == 0) {
				unset($uncaptured[$row['amazon_capture_id']]);
			}
		}

		return array_values($uncaptured);
	}

	/**
	 * Update Refund Status
	 *
	 * @param int $amazon_login_pay_order_id
	 * @param int $status
	 *
	 * @return void
	 */
	public function updateRefundStatus(int $amazon_login_pay_order_id, int $status): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "amazon_login_pay_order` SET `refund_status` = '" . (int)$status . "' WHERE `amazon_login_pay_order_id` = '" . (int)$amazon_login_pay_order_id . "'");
	}

	/**
	 * Get Captures Remaining
	 *
	 * @param int $amazon_login_pay_order_id
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getCapturesRemaining(int $amazon_login_pay_order_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "amazon_login_pay_order_transaction` WHERE `amazon_login_pay_order_id` = '" . (int)$amazon_login_pay_order_id . "' AND capture_remaining != '0' ORDER BY `date_added`");

		if ($query->num_rows) {
			return $query->rows;
		} else {
			return [];
		}
	}

	/**
	 * Get Transactions
	 *
	 * @param int    $amazon_login_pay_order_id
	 * @param string $currency_code
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getTransactions(int $amazon_login_pay_order_id, string $currency_code): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "amazon_login_pay_order_transaction` WHERE `amazon_login_pay_order_id` = '" . (int)$amazon_login_pay_order_id . "' ORDER BY `date_added` DESC");

		$transactions = [];

		if ($query->num_rows) {
			foreach ($query->rows as $row) {
				$row['amount'] = $this->currency->format($row['amount'], $currency_code, true, true);
				$transactions[] = $row;
			}

			return $transactions;
		} else {
			return [];
		}
	}

	/**
	 * Add Transaction
	 *
	 * @param int    $amazon_login_pay_order_id
	 * @param string $type
	 * @param string $status
	 * @param float  $total
	 * @param string $amazon_authorization_id
	 * @param string $amazon_capture_id
	 * @param string $amazon_refund_id
	 *
	 * @return void
	 */
	public function addTransaction(int $amazon_login_pay_order_id, string $type, string $status, float $total, ?string $amazon_authorization_id = null, ?string $amazon_capture_id = null, ?string $amazon_refund_id = null): void {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "amazon_login_pay_order_transaction` SET `amazon_login_pay_order_id` = '" . (int)$amazon_login_pay_order_id . "',`amazon_authorization_id` = '" . $this->db->escape($amazon_authorization_id) . "', `amazon_capture_id` = '" . $this->db->escape($amazon_capture_id) . "', `amazon_refund_id` = '" . $this->db->escape($amazon_refund_id) . "', `date_added` = NOW(), `type` = '" . $this->db->escape($type) . "', `amount` = '" . (float)$total . "', `status` = '" . $this->db->escape($status) . "'");
	}

	/**
	 * Update Authorization Status
	 *
	 * @param string $amazon_authorization_id
	 * @param string $status
	 *
	 * @return void
	 */
	public function updateAuthorizationStatus(string $amazon_authorization_id, string $status): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "amazon_login_pay_order_transaction` SET `status` = '" . $this->db->escape($status) . "' WHERE `amazon_authorization_id` = '" . $this->db->escape($amazon_authorization_id) . "' AND `type` = 'authorization'");
	}

	/**
	 * Is Order In State
	 *
	 * @param string               $order_reference_id
	 * @param array<string, mixed> $states
	 *
	 * @return bool
	 */
	public function isOrderInState(string $order_reference_id, array $states = []): bool {
		return in_array((string)$this->fetchOrder($order_reference_id)->OrderReferenceStatus->State, $states);
	}

	/**
	 * Fetch Order
	 *
	 * @param string $order_reference_id
	 *
	 * @return object|null
	 */
	public function fetchOrder(string $order_reference_id): ?object {
		$order = $this->offAmazon('GetOrderReferenceDetails', [
			'AmazonOrderReferenceId' => $order_reference_id
		]);

		$responseBody = $order['ResponseBody'];

		$details_xml = simplexml_load_string($responseBody);

		if ($details_xml) {
			return $details_xml->GetOrderReferenceDetailsResult->OrderReferenceDetails;
		} else {
			return null;
		}
	}

	/**
	 * Get Total Captured
	 *
	 * @param int $amazon_login_pay_order_id
	 *
	 * @return float
	 */
	public function getTotalCaptured(int $amazon_login_pay_order_id): float {
		$query = $this->db->query("SELECT SUM(`amount`) AS `total` FROM `" . DB_PREFIX . "amazon_login_pay_order_transaction` WHERE `amazon_login_pay_order_id` = '" . (int)$amazon_login_pay_order_id . "' AND (`type` = 'capture' OR `type` = 'refund') AND (`status` = 'Completed' OR `status` = 'Closed')");

		return (float)$query->row['total'];
	}

	/**
	 * Get Total Refunded
	 *
	 * @param int $amazon_login_pay_order_id
	 *
	 * @return float
	 */
	public function getTotalRefunded(int $amazon_login_pay_order_id): float {
		$query = $this->db->query("SELECT SUM(`amount`) AS `total` FROM `" . DB_PREFIX . "amazon_login_pay_order_transaction` WHERE `amazon_login_pay_order_id` = '" . (int)$amazon_login_pay_order_id . "' AND `type` = 'refund'");

		return (float)$query->row['total'];
	}

	/**
	 * Validate Details
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return array<string, mixed>
	 */
	public function validateDetails(array $data): array {
		$validate_parameter_data = [];

		$validate_parameter_data['AWSAccessKeyId'] = $data['payment_amazon_login_pay_access_key'];
		$validate_parameter_data['SellerId'] = $data['payment_amazon_login_pay_merchant_id'];
		$validate_parameter_data['AmazonOrderReferenceId'] = 'validate details';

		$validate_details = $this->offAmazon('GetOrderReferenceDetails', $validate_parameter_data);
		$validate_response = $this->validateResponse('GetOrderReferenceDetails', $validate_details, true);

		if ($validate_response['error_code'] && $validate_response['error_code'] != 'InvalidOrderReferenceId') {
			return $validate_response;
		} else {
			return [];
		}
	}

	/**
	 * Off Amazon
	 *
	 * @param string               $action
	 * @param array<string, mixed> $parameter_data
	 * @param array<string, mixed> $post_data
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function offAmazon(string $action, array $parameter_data, array $post_data = []): array {
		if (!empty($post_data)) {
			$merchant_id = $post_data['payment_amazon_login_pay_merchant_id'];
			$access_key = $post_data['payment_amazon_login_pay_access_key'];
			$access_secret = $post_data['payment_amazon_login_pay_access_secret'];
			$test = $post_data['payment_amazon_login_pay_test'];
			$payment_region = $post_data['payment_amazon_login_pay_payment_region'];
		} else {
			$merchant_id = $this->config->get('payment_amazon_login_pay_merchant_id');
			$access_key = $this->config->get('payment_amazon_login_pay_access_key');
			$access_secret = $this->config->get('payment_amazon_login_pay_access_secret');
			$test = $this->config->get('payment_amazon_login_pay_test');
			$payment_region = $this->config->get('payment_amazon_login_pay_payment_region');
		}

		if ($test == 'sandbox') {
			if ($payment_region == 'USD') {
				$url = 'https://mws.amazonservices.com/OffAmazonPayments_Sandbox/2013-01-01/';
			} else {
				$url = 'https://mws-eu.amazonservices.com/OffAmazonPayments_Sandbox/2013-01-01/';
			}
		} else {
			if ($payment_region == 'USD') {
				$url = 'https://mws.amazonservices.com/OffAmazonPayments/2013-01-01/';
			} else {
				$url = 'https://mws-eu.amazonservices.com/OffAmazonPayments/2013-01-01/';
			}
		}

		$parameters = [];

		$parameters['SignatureVersion'] = 2;
		$parameters['AWSAccessKeyId'] = $access_key;
		$parameters['Action'] = $action;
		$parameters['SellerId'] = $merchant_id;
		$parameters['SignatureMethod'] = 'HmacSHA256';
		$parameters['Timestamp'] = date('c', time());
		$parameters['Version'] = '2013-01-01';

		foreach ($parameter_data as $k => $v) {
			$parameters[$k] = $v;
		}

		$query = $this->calculateStringToSignV2($parameters, $url);

		$parameters['Signature'] = base64_encode(hash_hmac('sha256', $query, $access_secret, true));

		return $this->sendCurl($url, $parameters);
	}

	/**
	 * Validate Response
	 *
	 * @param string               $action
	 * @param array<string, mixed> $details
	 * @param bool                 $skip_logger
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function validateResponse(string $action, array $details, bool $skip_logger = false): array {
		$details_xml = simplexml_load_string($details['ResponseBody']);

		if (!$skip_logger) {
			$this->logger($details_xml);
		}

		switch ($action) {
			case 'Authorize':
				$result = 'AuthorizeResult';
				$details = 'AuthorizationDetails';
				$status = 'AuthorizationStatus';
				$amazon_id = 'AmazonAuthorizationId';
				break;
			case 'Capture':
				$result = 'CaptureResult';
				$details = 'CaptureDetails';
				$status = 'CaptureStatus';
				$amazon_id = 'AmazonCaptureId';
				break;
			case 'Refund':
				$result = 'RefundResult';
				$details = 'RefundDetails';
				$status = 'RefundStatus';
				$amazon_id = 'AmazonRefundId';
		}

		$details_xml->registerXPathNamespace('m', 'http://mws.amazonservices.com/schema/OffAmazonPayments/2013-01-01');

		$error_set = $details_xml->xpath('//m:ReasonCode');

		$response = [];

		if (isset($details_xml->Error)) {
			$response['status'] = 'Error';
			$response['error_code'] = (string)$details_xml->Error->Code;
			$response['status_detail'] = (string)$details_xml->Error->Code . ': ' . (string)$details_xml->Error->Message;
		} elseif (isset($result) && isset($details) && isset($status) && isset($amazon_id)) {
			if (!empty($error_set)) {
				$response['status'] = (string)$details_xml->{$result}->{$details}->{$status}->State;
				$response['status_detail'] = (string)$details_xml->{$result}->{$details}->{$status}->ReasonCode;
			} else {
				$response['status'] = (string)$details_xml->{$result}->{$details}->{$status}->State;
				$response[$amazon_id] = (string)$details_xml->{$result}->{$details}->{$amazon_id};
			}
		}

		return $response;
	}

	/**
	 * Send Curl
	 *
	 * @param string               $url
	 * @param array<string, mixed> $parameters
	 *
	 * @return array<string, mixed>
	 */
	public function sendCurl(string $url, array $parameters): array {
		$query = $this->getParametersAsString($parameters);

		$curl = curl_init($url);

		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_PORT, 443);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($curl, CURLOPT_USERAGENT, $this->request->server['HTTP_USER_AGENT']);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $query);
		curl_setopt($curl, CURLOPT_HEADER, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

		$response = curl_exec($curl);

		curl_close($curl);

		[$other, $responseBody] = explode("\r\n\r\n", $response, 2);

		$other = preg_split("/\r\n|\n|\r/", $other);

		[$protocol, $code, $text] = explode(' ', trim(array_shift($other)), 3);

		return [
			'status'       => (int)$code,
			'ResponseBody' => $responseBody
		];
	}

	/**
	 * Get Parameters As String
	 *
	 * @param array<string, mixed> $parameters
	 *
	 * @return string
	 */
	private function getParametersAsString(array $parameters): string {
		$queryParameters = [];

		foreach ($parameters as $key => $value) {
			$queryParameters[] = $key . '=' . $this->urlencode($value);
		}

		return implode('&', $queryParameters);
	}

	/**
	 * Calculate String To Sign V2
	 *
	 * @param array<string, mixed> $parameters
	 * @param string               $url
	 *
	 * @return string
	 */
	private function calculateStringToSignV2(array $parameters, string $url): string {
		$data = 'POST';
		$data .= "\n";
		$endpoint = parse_url($url);
		$data .= $endpoint['host'];
		$data .= "\n";
		$uri = array_key_exists('path', $endpoint) ? $endpoint['path'] : null;

		if (!isset($uri)) {
			$uri = '/';
		}

		$uriencoded = implode('/', array_map([$this, 'urlencode'], explode('/', $uri)));

		$data .= $uriencoded;
		$data .= "\n";

		uksort($parameters, 'strcmp');

		$data .= $this->getParametersAsString($parameters);

		return $data;
	}

	/**
	 * Urlencode
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	private function urlencode(string $value): string {
		return str_replace('%7E', '~', rawurlencode($value));
	}

	/**
	 * Logger
	 *
	 * @param mixed $message
	 *
	 * @return void
	 */
	public function logger($message): void {
		if ($this->config->get('payment_amazon_login_pay_debug') == 1) {
			$log = new \Log('amazon_login_pay_admin.log');

			$backtrace = debug_backtrace();
			$class = (isset($backtrace[6]['class']) ? $backtrace[6]['class'] . '::' : '');

			$log->write('Origin: ' . $class . $backtrace[6]['function']);
			$log->write(!is_string($message) ? print_r($message, true) : $message);

			unset($log);
		}
	}
}
