<?php
/**
 * Class G2apay
 *
 * @package Catalog\Controller\Extension\Payment
 */
class ControllerExtensionPaymentG2APay extends Controller {
	/**
	 * Index
	 *
	 * @return string
	 */
	public function index(): string {
		$this->load->language('extension/payment/g2apay');

		$data['action'] = $this->url->link('extension/payment/g2apay/checkout', '', true);

		return $this->load->view('extension/payment/g2apay', $data);
	}

	/**
	 * Checkout
	 *
	 * @return void
	 */
	public function checkout(): void {
		if (!isset($this->session->data['order_id'])) {
			return;
		}

		// Account Order
		$this->load->model('account/order');

		// Checkout Order
		$this->load->model('checkout/order');

		// G2apay
		$this->load->model('extension/payment/g2apay');

		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

		$order_data = [];

		$totals = [];
		$taxes = $this->cart->getTaxes();
		$total = 0;

		// Because __call can not keep var references, so we put them into an array.
		$total_data = [
			'totals' => &$totals,
			'taxes'  => &$taxes,
			'total'  => &$total
		];

		// Extensions
		${$this}->load->model('setting/extension');

		$results = $this->model_setting_extension->getExtensionsByType('total');

		foreach ($results as $result) {
			if ($this->config->get('total_' . $result['code'] . '_status')) {
				$total_info = $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);

				if ($total_info) {
					$item = new \stdClass();

					$item->sku = $total_info['totals']['code'];
					$item->name = $total_info['totals']['title'];
					$item->amount = number_format($total_info['totals']['value'], 2);
					$item->qty = 1;
					$item->id = $total_info['totals']['code'];
					$item->price = $total_info['totals']['value'];
					$item->url = $this->url->link('common/home', '', true);

					$items[] = $item;
				}
			}
		}

		$ordered_products = $this->model_account_order->getProducts($this->session->data['order_id']);

		foreach ($ordered_products as $product) {
			$item = new \stdClass();

			$item->sku = $product['product_id'];
			$item->name = $product['name'];
			$item->amount = $product['price'] * $product['quantity'];
			$item->qty = $product['quantity'];
			$item->id = $product['product_id'];
			$item->price = $product['price'];
			$item->url = $this->url->link('product/product', 'product_id=' . $product['product_id'], true);

			$items[] = $item;
		}

		if ($this->config->get('payment_g2apay_environment') == 1) {
			$url = 'https://checkout.pay.g2a.com/index/createQuote';
		} else {
			$url = 'https://checkout.test.pay.g2a.com/index/createQuote';
		}

		$order_total = number_format($order_info['total'], 2);
		$string = $this->session->data['order_id'] . $order_total . $order_info['currency_code'] . html_entity_decode($this->config->get('payment_g2apay_secret'));

		$fields = [
			'api_hash'    => $this->config->get('payment_g2apay_api_hash'),
			'hash'        => hash('sha256', $string),
			'order_id'    => $this->session->data['order_id'],
			'amount'      => $order_total,
			'currency'    => $order_info['currency_code'],
			'email'       => $order_info['email'],
			'url_failure' => $this->url->link('checkout/failure'),
			'url_ok'      => $this->url->link('extension/payment/g2apay/success'),
			'items'       => isset($items) ? json_encode($items) : ''
		];

		$response_data = $this->model_extension_payment_g2apay->sendCurl($url, $fields);

		$this->model_extension_payment_g2apay->logger($order_total);
		$this->model_extension_payment_g2apay->logger($fields);

		if (isset($items)) {
			$this->model_extension_payment_g2apay->logger($items);
		}

		if ($response_data === false) {
			$this->response->redirect($this->url->link('checkout/failure', '', true));
		}

		if (isset($response_data['status'])) {
			$status = (string)$response_data['status'];

			if (strtolower($status) != 'ok') {
				$this->response->redirect($this->url->link('checkout/failure', '', true));
			}
		}

		$this->model_extension_payment_g2apay->addG2aOrder($order_info);

		if (isset($response_data['token'])) {
			$response_token = (string)$response_data['token'];

			if ($this->config->get('payment_g2apay_environment') == 1) {
				$this->response->redirect('https://checkout.pay.g2a.com/index/gateway?token=' . $response_token);
			} else {
				$this->response->redirect('https://checkout.test.pay.g2a.com/index/gateway?token=' . $response_token);
			}
		}
	}

	/**
	 * Success
	 *
	 * @return void
	 */
	public function success(): void {
		$order_id = (int)$this->session->data['order_id'];

		if (isset($this->request->post['transaction_id'])) {
			$g2apay_transaction_id = $this->request->post['transaction_id'];
		} elseif (isset($this->request->get['transaction_id'])) {
			$g2apay_transaction_id = $this->request->get['transaction_id'];
		} else {
			$g2apay_transaction_id = '';
		}

		// Orders
		$this->load->model('checkout/order');

		$order_info = $this->model_checkout_order->getOrder($order_id);

		if ($order_info) {
			// G2apay
			$this->load->model('extension/payment/g2apay');

			$g2apay_order_info = $this->model_extension_payment_g2apay->getG2aOrder($order_id);

			$this->model_extension_payment_g2apay->updateOrder($g2apay_order_info['g2apay_order_id'], $g2apay_transaction_id, 'payment', $order_info);

			$this->model_checkout_order->addHistory($order_id, $this->config->get('payment_g2apay_order_status_id'));
		}

		$this->response->redirect($this->url->link('checkout/success'));
	}

	/**
	 * Ipn
	 *
	 * @return void
	 */
	public function ipn(): void {
		// Orders
		$this->load->model('checkout/order');

		// G2apay
		$this->load->model('extension/payment/g2apay');

		$this->model_extension_payment_g2apay->logger('ipn');

		if (isset($this->request->get['token']) && hash_equals($this->config->get('payment_g2apay_secret_token'), $this->request->get['token'])) {
			$this->model_extension_payment_g2apay->logger('token success');

			if (isset($this->request->post['userOrderId'])) {
				$g2apay_order = $this->model_extension_payment_g2apay->getG2aOrder($this->request->post['userOrderId']);

				$string = $g2apay_order['g2apay_transaction_id'] . $g2apay_order['order_id'] . round($g2apay_order['total'], 2) . html_entity_decode($this->config->get('payment_g2apay_secret'));
				$hash = hash('sha256', $string);

				if ($hash != $this->request->post['hash']) {
					$this->model_extension_payment_g2apay->logger('Hashes do not match, possible tampering!');

					return;
				}

				$order_status_id = 0;

				switch ($this->request->post['status']) {
					case 'complete':
						$order_status_id = (int)$this->config->get('payment_g2apay_complete_status_id');
						break;
					case 'rejected':
						$order_status_id = (int)$this->config->get('payment_g2apay_rejected_status_id');
						break;
					case 'canceled':
						$order_status_id = (int)$this->config->get('payment_g2apay_cancelled_status_id');
						break;
					case 'partial_refunded':
						$order_status_id = (int)$this->config->get('payment_g2apay_partially_refunded_status_id');
						break;
					case 'refunded':
						$order_status_id = (int)$this->config->get('payment_g2apay_refunded_status_id');
						break;
				}

				$this->model_checkout_order->addHistory($this->request->post['userOrderId'], $order_status_id);
			}
		}
	}
}
