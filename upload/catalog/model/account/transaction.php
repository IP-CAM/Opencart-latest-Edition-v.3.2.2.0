<?php
/**
 * Class Transaction
 *
 * Can be called using $this->load->model('account/transaction');
 *
 * @package Catalog\Model\Account
 */
class ModelAccountTransaction extends Model {
	/**
	 * Get Transactions
	 *
	 * @param array<string, mixed> $data array of filters
	 *
	 * @return array<int, array<string, mixed>> transaction records
	 *
	 * @example
	 *
	 * $this->load->model('account/transaction');
	 *
	 * $results = $this->model_account_transaction->getTransactions();
	 */
	public function getTransactions(array $data = []): array {
		$sql = "SELECT * FROM `" . DB_PREFIX . "customer_transaction` WHERE `customer_id` = '" . (int)$this->customer->getId() . "'";

		$sort_data = [
			'amount',
			'description',
			'date_added'
		];

		if (isset($data['sort']) && in_array($data['sort'], $sort_data)) {
			$sql .= " ORDER BY " . $data['sort'];
		} else {
			$sql .= " ORDER BY `date_added`";
		}

		if (isset($data['order']) && ($data['order'] == 'DESC')) {
			$sql .= " DESC";
		} else {
			$sql .= " ASC";
		}

		if (isset($data['start']) || isset($data['limit'])) {
			if ($data['start'] < 0) {
				$data['start'] = 0;
			}

			if ($data['limit'] < 1) {
				$data['limit'] = 20;
			}

			$sql .= " LIMIT " . (int)$data['start'] . "," . (int)$data['limit'];
		}

		$query = $this->db->query($sql);

		return $query->rows;
	}

	/**
	 * Get Total Transactions
	 *
	 * @return int total number of transaction records
	 *
	 * @example
	 *
	 * $this->load->model('account/transaction');
	 *
	 * $transaction_total = $this->model_account_transaction->getTotalTransactions();
	 */
	public function getTotalTransactions(): int {
		$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "customer_transaction` WHERE `customer_id` = '" . (int)$this->customer->getId() . "'");

		return (int)$query->row['total'];
	}

	/**
	 * Get Total Amount
	 *
	 * @return int total number of transaction amount records
	 *
	 * @example
	 *
	 * $amount_total = $this->model_account_transaction->getTotalAmount();
	 */
	public function getTotalAmount(): int {
		$query = $this->db->query("SELECT SUM(`amount`) AS `total` FROM `" . DB_PREFIX . "customer_transaction` WHERE `customer_id` = '" . (int)$this->customer->getId() . "' GROUP BY `customer_id`");

		if ($query->num_rows) {
			return (int)$query->row['total'];
		} else {
			return 0;
		}
	}
}
