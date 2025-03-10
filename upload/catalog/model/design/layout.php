<?php
/**
 * Class Layout
 *
 * Can be called using $this->load->model('design/layout');
 *
 * @package Catalog\Model\Design
 */
class ModelDesignLayout extends Model {
	/**
	 * Get Layout
	 *
	 * @param string $route
	 *
	 * @return int
	 *
	 * @example
	 *
	 * $this->load->model('design/layout');
	 *
	 * $layout_id = $this->model_design_layout->getLayout($route);
	 */
	public function getLayout(string $route): int {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "layout_route` WHERE '" . $this->db->escape($route) . "' LIKE route AND `store_id` = '" . (int)$this->config->get('config_store_id') . "' ORDER BY `route` DESC LIMIT 1");

		if ($query->num_rows) {
			return (int)$query->row['layout_id'];
		} else {
			return 0;
		}
	}

	/**
	 * Get Modules
	 *
	 * @param int    $layout_id primary key of the layout record
	 * @param string $position
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @example
	 *
	 * $modules = $this->model_design_layout->getModules($layout_id, $position);
	 */
	public function getModules(int $layout_id, string $position): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "layout_module` WHERE `layout_id` = '" . (int)$layout_id . "' AND `position` = '" . $this->db->escape($position) . "' ORDER BY `sort_order`");

		return $query->rows;
	}
}
