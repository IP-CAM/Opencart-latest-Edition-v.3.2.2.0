<?php
/**
 * Class Translation
 *
 * Can be called using $this->load->model('design/translation');
 *
 * @package Catalog\Model\Design
 */
class ModelDesignTranslation extends Model {
	/**
	 * Get Translations
	 *
	 * @param string $route
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @example
	 *
	 * $this->load->model('design/translation');
	 *
	 * $translation = $this->model_design_translation->getTranslations($route);
	 */
	public function getTranslations(string $route): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "translation` WHERE `store_id` = '" . (int)$this->config->get('config_store_id') . "' AND `language_id` = '" . (int)$this->config->get('config_language_id') . "' AND `route` = '" . $this->db->escape($route) . "'");

		return $query->rows;
	}
}
