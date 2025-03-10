<?php
/**
 * Class Wechat Pay
 *
 * @package Admin\Controller\Extension\Payment
 * @package        OpenCart
 *
 * @author         Meng Wenbin
 * @copyright      Copyright (c) 2010 - 2022, Chengdu Guangda Network Technology Co. Ltd. (https://www.opencart.cn/)
 * @license        https://opensource.org/licenses/GPL-3.0
 *
 * @see           https://www.opencart.cn
 */
class ControllerExtensionPaymentWechatPay extends Controller {
	/**
	 * @var array<string, string>
	 */
	private array $error = [];

	/**
	 * Index
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('extension/payment/wechat_pay');

		$this->document->setTitle($this->language->get('heading_title'));

		// Settings
		$this->load->model('setting/setting');

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('payment_wechat_pay', $this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
		}

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		if (isset($this->error['app_id'])) {
			$data['error_app_id'] = $this->error['app_id'];
		} else {
			$data['error_app_id'] = '';
		}

		if (isset($this->error['app_secret'])) {
			$data['error_app_secret'] = $this->error['app_secret'];
		} else {
			$data['error_app_secret'] = '';
		}

		if (isset($this->error['mch_id'])) {
			$data['error_mch_id'] = $this->error['mch_id'];
		} else {
			$data['error_mch_id'] = '';
		}

		if (isset($this->error['api_secret'])) {
			$data['error_api_secret'] = $this->error['api_secret'];
		} else {
			$data['error_api_secret'] = '';
		}

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/payment/wechat_pay', 'user_token=' . $this->session->data['user_token'], true)
		];

		$data['action'] = $this->url->link('extension/payment/wechat_pay', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

		if (isset($this->request->post['payment_wechat_pay_app_id'])) {
			$data['payment_wechat_pay_app_id'] = $this->request->post['payment_wechat_pay_app_id'];
		} else {
			$data['payment_wechat_pay_app_id'] = $this->config->get('payment_wechat_pay_app_id');
		}

		if (isset($this->request->post['payment_wechat_pay_app_secret'])) {
			$data['payment_wechat_pay_app_secret'] = $this->request->post['payment_wechat_pay_app_secret'];
		} else {
			$data['payment_wechat_pay_app_secret'] = $this->config->get('payment_wechat_pay_app_secret');
		}

		if (isset($this->request->post['payment_wechat_pay_mch_id'])) {
			$data['payment_wechat_pay_mch_id'] = $this->request->post['payment_wechat_pay_mch_id'];
		} else {
			$data['payment_wechat_pay_mch_id'] = $this->config->get('payment_wechat_pay_mch_id');
		}

		if (isset($this->request->post['payment_wechat_pay_api_secret'])) {
			$data['payment_wechat_pay_api_secret'] = $this->request->post['payment_wechat_pay_api_secret'];
		} else {
			$data['payment_wechat_pay_api_secret'] = $this->config->get('payment_wechat_pay_api_secret');
		}

		if (isset($this->request->post['payment_wechat_pay_total'])) {
			$data['payment_wechat_pay_total'] = $this->request->post['payment_wechat_pay_total'];
		} else {
			$data['payment_wechat_pay_total'] = $this->config->get('payment_wechat_pay_total');
		}

		if (isset($this->request->post['payment_wechat_pay_currency'])) {
			$data['payment_wechat_pay_currency'] = $this->request->post['payment_wechat_pay_currency'];
		} else {
			$data['payment_wechat_pay_currency'] = $this->config->get('payment_wechat_pay_currency');
		}

		if (isset($this->request->post['payment_wechat_pay_completed_status_id'])) {
			$data['payment_wechat_pay_completed_status_id'] = (int)$this->request->post['payment_wechat_pay_completed_status_id'];
		} else {
			$data['payment_wechat_pay_completed_status_id'] = (int)$this->config->get('payment_wechat_pay_completed_status_id');
		}

		// Order Statuses
		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		if (isset($this->request->post['payment_wechat_pay_geo_zone_id'])) {
			$data['payment_wechat_pay_geo_zone_id'] = (int)$this->request->post['payment_wechat_pay_geo_zone_id'];
		} else {
			$data['payment_wechat_pay_geo_zone_id'] = (int)$this->config->get('payment_wechat_pay_geo_zone_id');
		}

		// Geo Zones
		$this->load->model('localisation/geo_zone');

		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		if (isset($this->request->post['payment_wechat_pay_status'])) {
			$data['payment_wechat_pay_status'] = (int)$this->request->post['payment_wechat_pay_status'];
		} else {
			$data['payment_wechat_pay_status'] = $this->config->get('payment_wechat_pay_status');
		}

		if (isset($this->request->post['payment_wechat_pay_sort_order'])) {
			$data['payment_wechat_pay_sort_order'] = (int)$this->request->post['payment_wechat_pay_sort_order'];
		} else {
			$data['payment_wechat_pay_sort_order'] = $this->config->get('payment_wechat_pay_sort_order');
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/payment/wechat_pay', $data));
	}

	/**
	 * Validate
	 *
	 * @return bool
	 */
	private function validate(): bool {
		if (!$this->user->hasPermission('modify', 'extension/payment/wechat_pay')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		if (!$this->request->post['payment_wechat_pay_app_id']) {
			$this->error['app_id'] = $this->language->get('error_app_id');
		}

		if (!$this->request->post['payment_wechat_pay_app_secret']) {
			$this->error['app_secret'] = $this->language->get('error_app_secret');
		}

		if (!$this->request->post['payment_wechat_pay_mch_id']) {
			$this->error['mch_id'] = $this->language->get('error_mch_id');
		}

		if (!$this->request->post['payment_wechat_pay_api_secret']) {
			$this->error['api_secret'] = $this->language->get('error_api_secret');
		}

		return !$this->error;
	}
}
