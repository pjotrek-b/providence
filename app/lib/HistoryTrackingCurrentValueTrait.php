<?php
/** ---------------------------------------------------------------------
 * app/lib/HistoryTrackingCurrentValueTrait.php : 
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2018 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
 *
 * This source code is free and modifiable under the terms of 
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 * 
 * @package CollectiveAccess
 * @subpackage BaseModel
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 * 
 * ----------------------------------------------------------------------
 */
 
 /**
  * Methods for models than can have a current location
  */
  	require_once(__CA_MODELS_DIR__."/ca_history_tracking_current_values.php");
 
	trait HistoryTrackingCurrentValueTrait {
		# ------------------------------------------------------
		
		/**
		 *
		 */
		static $s_history_tracking_current_value_type_configuration_cache = [];
		
		# ------------------------------------------------------
		/**
		 *
		 */
		static public function clearHistoryTrackingCurrentValues($options=null) {
			$db = new Db();
			$db->query("TRUNCATE TABLE ca_history_tracking_current_values");
			return true;
		}
		# ------------------------------------------------------
		/**
		 *
		 */
		static public function getHistoryTrackingCurrentValuePolicyConfig($options=null) {
			$o_config = Configuration::load();
			
			$history_tracking_policies = $o_config->getAssoc('history_tracking_policies');
			if(!is_array($history_tracking_policies) || !is_array($history_tracking_policies['policies'])) {
			
				// Fall back to legacy "current_location_criteria" if no current configuration
				
				// TODO: rewrite map such that all keys are items types; move relationship type keys into restrictToRelationshipTypes options
				if(is_array($map = $o_config->getAssoc('current_location_criteria'))) {
				 	$history_tracking_policies = [
				 		'defaults' => [
				 			'ca_objects' => '_default_',
				 		],
						'policies' => [
							'_default_' => [
								'name' => 'Current location',
								'table' => 'ca_objects',
								'mode' => 'workflow',
								'elements' => $map
							]
						]
				 	];
				 }
			}
			
			return $history_tracking_policies;
		}
		# ------------------------------------------------------
		/**
		 * Convert policy configuration to bundle config HistoryTrackingCurrentValueTrait::getHistory() can use.
		 *
		 * @param array $options Options include:
		 *		policy = Name of policy to apply. If omitted, legacy 'current_location_criteria' configuration will be used if present, otherwise a null value will be returned. [Default is null]
		 *
		 * @return array Configuration array or null if policy is not available.
		 */
		static public function policy2bundleconfig($options=null) {
			$t_rel_type = new ca_relationship_types();
			$policy = caGetOption('policy', $options, null);
			$bundle_settings = [];
			$map = $policy_info = null;
			
			$history_tracking_policies = self::getHistoryTrackingCurrentValuePolicyConfig();
			
			if ($policy && is_array($history_tracking_policies) && is_array($history_tracking_policies['policies']) && is_array($history_tracking_policies['policies'][$policy])) {
				$policy_info = $history_tracking_policies['policies'][$policy];
				$map = $policy_info['elements'];
			}
			if(!is_array($map)){ return null; }
			
			if (!($policy_table = caGetOption('table', $policy_info, false))) { return []; }
			
			foreach($map as $table => $types) {
				$path = array_keys(Datamodel::getPath($policy_table, $table));
				$t_instance = Datamodel::getInstance($table, true);
				
				$bundle_settings["{$table}_showTypes"] = [];
				if(is_array($types)) {
					foreach($types as $type_list => $config) {
						
						if (in_array($type_list, ['*', '__default__'])) { 
							$bundle_settings["{$table}_displayTemplate"] = $config['template'];
							$types = array_map(function($v) { return $v['idno']; }, $t_instance->getTypeList());
						} else {
							$types = preg_split("![ ]*[,;]{1}[ ]*!", $type_list);
						}
						foreach($types as $type) {
							if(!is_array($config)) { break; }
							$bundle_settings["{$table}_{$type}_displayTemplate"] = $config['template'];
							$bundle_settings["{$table}_showTypes"][] = array_shift(caMakeTypeIDList($table, [$type]));
						
							$bundle_settings["{$table}_{$type}_dateElement"] = $config['date'];
						
							if ((sizeof($path) === 3) && ($rel_types = caGetOption('restrictToRelationshipTypes', $config, null)) && $path[1]) { 
								$bundle_settings["{$table}_showRelationshipTypes"] = [];
								foreach($rel_types as $rel_type) {
									if (($rel_type_id = $t_rel_type->getRelationshipTypeID($path[1], $rel_type)) && !in_array($rel_type_id, $bundle_settings["{$table}_showRelationshipTypes"])) { 
										$bundle_settings["{$table}_showRelationshipTypes"][] = $rel_type_id;
									}
								}
							}
						}
					}
				}
			}
			return $bundle_settings;
		}
		# ------------------------------------------------------
		/**
		 * TODO: deprecate?
		 */
		private function _processHistoryBundleSettings($pa_bundle_settings) {

			if ($vb_use_app_defaults = caGetOption('useAppConfDefaults', $pa_bundle_settings, false)) {
				// Copy app.conf "current_location_criteria" settings into bundle settings (with translation)
				$va_current_location_criteria = self::policy2bundleconfig(['policy' => caGetOption('policy', $pa_bundle_settings, $this->getDefaultHistoryTrackingCurrentValuePolicy())]);
			
				$va_bundle_settings = [];
				foreach($va_current_location_criteria as $vs_table => $va_info) {
					switch($vs_table) {
						case 'ca_storage_locations':
							if(is_array($va_info)) {
								foreach($va_info as $vs_rel_type => $va_options) {
									$va_bundle_settings["{$vs_table}_showRelationshipTypes"][] = $vs_rel_type;
									foreach($va_options as $vs_opt => $vs_opt_val) {
										switch($vs_opt) {
											case 'template':
												$vs_opt = 'displayTemplate';
												break;
										}
										$va_bundle_settings["{$vs_table}_{$vs_opt}"] = $vs_opt_val;
									}
								}
								$va_bundle_settings["{$vs_table}_showRelationshipTypes"] = caMakeRelationshipTypeIDList('ca_objects_x_storage_locations', $va_bundle_settings["{$vs_table}_showRelationshipTypes"]);
							}
							break;
						case 'ca_objects':
							if(is_array($va_info)) {
								$va_bundle_settings['showDeaccessionInformation'] = 1;
								foreach($va_info as $vs_opt => $vs_opt_val) {
									switch($vs_opt) {
										case 'template':
											$vs_opt = 'displayTemplate';
											break;
									}
									$va_bundle_settings["deaccession_{$vs_opt}"] = $vs_opt_val;
								}
							}
							break;
						default:
							if(is_array($va_info)) {
								foreach($va_info as $vs_type => $va_options) {
									if(!is_array($va_options)) { continue; }
									$va_bundle_settings["{$vs_table}_showTypes"][] = $vs_type;
									foreach($va_options as $vs_opt => $vs_opt_val) {
										switch($vs_opt) {
											case 'date':
												$vs_opt = 'dateElement';
												break;
											case 'template':
												$vs_opt = 'displayTemplate';
												break;
										}
										$va_bundle_settings["{$vs_table}_{$vs_type}_{$vs_opt}"] = $vs_opt_val;
									}
								}
								$va_bundle_settings["{$vs_table}_showTypes"] = caMakeTypeIDList($vs_table, $va_bundle_settings["{$vs_table}_showTypes"]);
							}
							break;
					}
				}

				foreach(array(
							'policy', 'locationTrackingMode', 'width', 'height', 'readonly', 'documentation_url', 'expand_collapse',
							'label', 'description', 'useHierarchicalBrowser', 'hide_add_to_loan_controls', 'hide_update_location_controls',
							'hide_add_to_occurrence_controls', 'hide_include_child_history_controls', 'add_to_occurrence_types', 'ca_storage_locations_elements', 'sortDirection'
						) as $vs_key) {
					if (isset($va_current_location_criteria[$vs_key]) && $vb_use_app_defaults) {
						$va_bundle_settings[$vs_key] = $va_current_location_criteria[$vs_key];
					} elseif(!$vb_use_app_defaults || !in_array($vs_key, ['sortDirection'])) {
						$va_bundle_settings[$vs_key] = $pa_bundle_settings[$vs_key];
					}
				}
				$pa_bundle_settings = $va_bundle_settings;
			}
		
			return $pa_bundle_settings;
		}
		# ------------------------------------------------------
		/**
		 * Test if policy is defined.
		 *
		 * @param string $policy
		 *
		 * @return bool
		 */
		static public function isValidHistoryTrackingCurrentValuePolicy($policy) {
			return self::getHistoryTrackingCurrentValuePolicy($policy) ? true : false;
		}
		# ------------------------------------------------------
		/**
		 * Return policy config
		 *
		 * @param string $policy
		 *
		 * @return array Policy or null if policy does not exist.
		 */
		static public function getHistoryTrackingCurrentValuePolicy($policy) {
			if ($policy && is_array($history_tracking_policies = self::getHistoryTrackingCurrentValuePolicyConfig()) && is_array($history_tracking_policies['policies']) && is_array($history_tracking_policies['policies'][$policy])) {
				return $history_tracking_policies['policies'][$policy];
			}
			return null;
		}
		# ------------------------------------------------------
		/**
		 * Return default policy for table
		 *
		 * @return string Policy name
		 */
		public function getDefaultHistoryTrackingCurrentValuePolicy() {
			return self::getDefaultHistoryTrackingCurrentValuePolicyForTable($this->tableName());
		}
		# ------------------------------------------------------
		/**
		 * Return default policy for table
		 *
		 * @param string $table Name of table
		 *
		 * @return string Policy name
		 */
		static public function getDefaultHistoryTrackingCurrentValuePolicyForTable($table) {
			if (is_array($history_tracking_policies = self::getHistoryTrackingCurrentValuePolicyConfig()) && is_array($history_tracking_policies['defaults']) && isset($history_tracking_policies['defaults'][$table])) {
				return $history_tracking_policies['defaults'][$table];
			}
			return null;
		}
		# ------------------------------------------------------
		/**
		 * Return policy to use when displaying tracking current value in editor inspector
		 *
		 * @return string Policy name
		 */
		public function getInspectorHistoryTrackingDisplayPolicy() {
			$table = $this->tableName();
			$type_code = $this->getTypeCode();
			
			$display_config = $this->getAppConfig()->get('inspector_tracking_displays');
			if (!isset($display_config[$table])) { return null; }
			if (!isset($display_config[$table][$type_code])) { $type_code = '__default__'; }
			
			if (!isset($display_config[$table][$type_code])) { 
				// Last ditch, try old config option. If it is set return it as label with default policy.
				if ($old_config_value = $this->getAppConfig()->get("{$table}_inspector_current_location_label")) { 
					return ['label' => $old_config_value, 'policy' => $this->getDefaultHistoryTrackingCurrentValuePolicy()]; 
				}
				return null; 
			}
			
			return $display_config[$table][$type_code];
		}
		# ------------------------------------------------------
		/**
		 * 
		 *
		 * @return 
		 */
		public static function getConfigurationForHistoryTrackingCurrentValue($policy, $table, $type_id=null, $options=null) {
			$cache_key = caMakeCacheKeyFromOptions($options, "{$policy}/{$table}/{$type_id}");
		
			if (isset(self::$s_history_tracking_current_value_type_configuration_cache[$cache_key])) { return self::$s_history_tracking_current_value_type_configuration_cache[$cache_key]; }
			$o_config = Configuration::load();
		
			$policy_config = self::getHistoryTrackingCurrentValuePolicy($policy); //$o_config->getAssoc('current_location_criteria');
			$map = $policy_config['elements'];
		
			if (!($t_instance = Datamodel::getInstance($table, true))) { return self::$s_history_tracking_current_value_type_configuration_cache[$cache_key] = null; }
	
			if (isset($map[$table])) {
				if ((!$type_id) && (isset($map[$table]['*']) || isset($map[$table]['__default__']))) { return self::$s_history_tracking_current_value_type_configuration_cache[caMakeCacheKeyFromOptions($options, "{$table}/{$type_id}")] = self::$s_history_tracking_current_value_type_configuration_cache[$cache_key] = $map[$table]['*']; }	// return default config if no type is specified
			
				if ($type_id) { 
					$type = $t_instance->getTypeCode($type_id);
				
					$facet_display_config = caGetOption('facet', $options, null); 
					if ($type && isset($map[$table][$type])) {
						if (is_array($facet_display_config) && isset($facet_display_config[$table][$type])) {
							$map[$table][$type] = array_merge($map[$table][$type], $facet_display_config[$table][$type]);
						}
						return self::$s_history_tracking_current_value_type_configuration_cache[caMakeCacheKeyFromOptions($options, "{$table}/{$type_id}")] = self::$s_history_tracking_current_value_type_configuration_cache[$cache_key] = $map[$table][$type];
					} elseif (isset($map[$table]['*']) || isset($map[$table]['__default__'])) {
						if(!isset($map[$table]['__default__'])) { $map[$table]['__default__'] = []; }
						if(!isset($map[$table]['*'])) { $map[$table]['*'] = []; }
						$map[$table][$type] = array_merge($map[$table]['*'], $map[$table]['__default__']);
						
						if (is_array($facet_display_config) && (isset($facet_display_config[$table]['*']) || isset($facet_display_config[$table]['__default__']))) {
							if(!isset($facet_display_config[$table]['__default__'])) { $facet_display_config[$table]['__default__'] = []; }
							if(!isset($facet_display_config[$table]['*'])) { $facet_display_config[$table]['*'] = []; }
							
							$map[$table][$type] = array_merge($map[$table][$type], $facet_display_config[$table]['*'], $facet_display_config[$table]['__default__']);
						}
						return self::$s_history_tracking_current_value_type_configuration_cache[caMakeCacheKeyFromOptions($options, "{$table}/{$type_id}")] = self::$s_history_tracking_current_value_type_configuration_cache[$cache_key] = $map[$table][$type];
					}
				} 
			}
			return self::$s_history_tracking_current_value_type_configuration_cache[caMakeCacheKeyFromOptions($options, "{$table}/{$type_id}")] = self::$s_history_tracking_current_value_type_configuration_cache[$cache_key] = null;
		}
		# ------------------------------------------------------
		/**
		 * Set current value for policy on current row.
		 * Will throw an exception if values are not valid table number/row_id combinations.
		 *
		 * @param string $policy
		 * @param array $values
		 * @param array $options Options include:
		 *		dontCheckRowIDs = Skip verification of row_id values. [Default is false]
		 *		row_id = Row id to use instead of currently loaded row. [Default is null]
		 *
		 * @return bool
		 * @throws ApplicationException
		 */
		public function setHistoryTrackingCurrentValue($policy, $values=null, $options=null) {
			if(!($row_id = caGetOption('row_id', $options, null)) && !($row_id = $this->getPrimaryKey())) { return null; }
			if(!self::isValidHistoryTrackingCurrentValuePolicy($policy)) { return null; }
			
			$subject_table_num = $this->tableNum();
			$subject_table = $this->tableName();
			
			if (is_null($values)) {			
				if ($l = ca_history_tracking_current_values::find(['policy' => $policy, 'table_num' => $subject_table_num, 'row_id' => $row_id], ['returnAs' => 'firstModelInstance'])) {
					$l->delete();
					return true;
				}
				return null;
			}
			
			if(!Datamodel::getTableName($values['current_table_num']) || !Datamodel::getTableName($values['tracked_table_num'])) {
				throw new ApplicationException(_t('Invalid table specification'));
			}
			
			if (!caGetOption('dontCheckRowIDs', $options, false)) {
				foreach([$values['current_table_num'] => $values['current_row_id'], $values['tracked_table_num'] => $values['tracked_row_id']] as $t => $id) {
					if (!($table = Datamodel::getTableName($t))) { continue; } 
					Datamodel::getInstance($table, true);
					if ($table::find($id, ['returnAs' => 'count']) == 0) {
						throw new ApplicationException(_t('Invalid row id'));
					}
				}
			}
			
			
			if (!($t = $subject_table::find($row_id, ['returnAs' => 'firstModelInstance']))) {
				throw new ApplicationException(_t('Invalid subject row id'));
			}
			
			if ($l = ca_history_tracking_current_values::find(['policy' => $policy, 'table_num' => $subject_table_num, 'row_id' => $row_id], ['returnAs' => 'firstModelInstance'])) {
				$l->delete();
			}
		
			$e = new ca_history_tracking_current_values();
			$e->set([
				'policy' => $policy,
				'table_num' => $subject_table_num, 
				'type_id' => $t->get("{$subject_table}.type_id"),
				'row_id' => $row_id, 
				'current_table_num' => $values['current_table_num'], 
				'current_type_id' => $values['current_type_id'], 
				'current_row_id' => $values['current_row_id'], 
				'tracked_table_num' => $values['tracked_table_num'], 
				'tracked_type_id' => $values['tracked_type_id'], 
				'tracked_row_id' => $values['tracked_row_id']
			]);
			if (!($rc = $e->insert())) {
				$this->errors = $e->errors;
				return false;
			}

			return $rc;
		}
		# ------------------------------------------------------
		/**
		 * Return configured policies for the specified table
		 *
		 * @param string $table 
		 * @param array $options Options include:
		 *		dontCheckRowIDs = Skip verification of row_id values. [Default is false]
		 *
		 * @return array
		 * @throws ApplicationException
		 */ 
		static public function getHistoryTrackingCurrentValuePolicies($table, $options=null) {
			$policy_config = self::getHistoryTrackingCurrentValuePolicyConfig();
			if(!is_array($policy_config) || !isset($policy_config['policies']) || !is_array($policy_config['policies'])) {
				// No policies are configured
				return [];
			}
			
			$policies = [];
			foreach($policy_config['policies'] as $policy => $policy_info) {
				if ($table !== $policy_info['table']) { continue; }
				// TODO: implement restrictToTypes; restrictToRelationshipTypes
				$policies[$policy] = $policy_info;
			}
			return $policies;
		}
		# ------------------------------------------------------
		/**
		 * Return policies that have some dependency on the specified table
		 *
		 * @param string $table 
		 * @param array $options Options include:
		 *		type_id = 
		 *
		 * @return array
		 * @throws ApplicationException
		 */ 
		static public function getDependentHistoryTrackingCurrentValuePolicies($table, $options=null) {
			$policy_config = self::getHistoryTrackingCurrentValuePolicyConfig();
			if(!is_array($policy_config) || !isset($policy_config['policies']) || !is_array($policy_config['policies'])) {
				// No policies are configured
				return [];
			}
			
			$type_id = caGetOption('type_id', $options, null);
			$types = caMakeTypeList($table, [$type_id]);
			$policies = [];
			foreach($policy_config['policies'] as $policy => $policy_info) {
				if ($table === $policy_info['table']) { continue; }
				if (!is_array($policy_info['elements'])) { continue; }
				
				foreach($policy_info['elements'] as $dtable => $dinfo) {
					$path = Datamodel::getPath($policy_info['table'], $dtable);
					if (!in_array($table, array_keys($path))) { continue; }
					if ($types && !sizeof(array_intersect(array_keys($dinfo), $types))) { continue; }
					$policies[$policy] = $policy_info;
					break;
				}
				
				
			}
			return $policies;
		}
		# ------------------------------------------------------
		/**
		 * Return list of tables for which tracking policies are configured
		 *
		 * @param array $options No options are currently supported
		 *
		 * @return array List of tables
		 */ 
		static public function getTablesWithHistoryTrackingPolicies($options=null) {
			$policy_config = self::getHistoryTrackingCurrentValuePolicyConfig();
			if(!is_array($policy_config) || !isset($policy_config['policies']) || !is_array($policy_config['policies'])) {
				// No policies are configured
				return [];
			}
			
			$tables = [];
			foreach($policy_config['policies'] as $policy => $policy_info) {
				$tables[$policy_info['table']] = true;
			}
			return array_keys($tables);
		}
		# ------------------------------------------------------
		/**
		 * Calculate and set for loaded row current values for all policies
		 *
		 * @param array $options Options include:
		 *		dontCheckRowIDs = Skip verification of row_id values. [Default is false]
		 *		row_id = Row id to use instead of currently loaded row. [Default is null]
		 *		omit_table_num = Tracked table number to ignore when deriving current value. This is used to omit an about-to-be-deleted value from the current value calculation. [Default is null]
		 *		omit_row_id =  Tracked row_id to ignore when deriving current value. This is used to omit an about-to-be-deleted value from the current value calculation. [Default is null]
		 *
		 * @return bool
		 * @throws ApplicationException
		 */ 
		public function deriveHistoryTrackingCurrentValue($options=null) {
			if(!($row_id = caGetOption('row_id', $options, null)) && !($row_id = $this->getPrimaryKey())) { return false; }
			if(is_array($policies = self::getHistoryTrackingCurrentValuePolicies($this->tableName()))) {
				foreach($policies as $policy => $policy_info) {
					SearchResult::clearResultCacheForRow($this->tableName(), $row_id);
					$h = $this->getHistory(['row_id' => $row_id, 'policy' => $policy, 'noCache' => true]);
	
					if(sizeof($h)) { 
						if(($omit_row_id = caGetOption('omit_row_id', $options, false)) && ($omit_table = caGetOption('omit_table_num', $options, false))) {
							$cl = array_reduce($h, function($c, $v) use ($omit_table, $omit_row_id) { 
								if ($c) { return $c; }
								$x = array_shift($v); 
								if (($x['tracked_table_num'] != $omit_table) || ($x['tracked_row_id'] != $omit_row_id)) { $c = $x; } 
								return $c;
							}, null);
						} else {
							$cl = array_shift(array_shift($h));
						}	
						if (!($this->setHistoryTrackingCurrentValue($policy, $cl, ['row_id' => $row_id]))) {
							return false;
						}
					} else {
						$this->setHistoryTrackingCurrentValue($policy, null, ['row_id' => $row_id]); // null values means remove current location entirely
					}
				}
				return true;
			}
			return false;
		}
		# ------------------------------------------------------
		/**
		 * Update current values for rows with policies that may be affected by changes to this row
		 *
		 * @param array $options Options include:
		 *		row_id = Row id to use instead of currently loaded row. [Default is null]
		 *
		 * @return bool
		 * @throws ApplicationException
		 */ 
		public function updateDependentHistoryTrackingCurrentValues($options=null) {
			if(!($row_id = caGetOption('row_id', $options, null)) && !($row_id = $this->getPrimaryKey())) { return false; }
			$mode = caGetOption('mode', $options, null);
			
			if(is_array($policies = self::getDependentHistoryTrackingCurrentValuePolicies($this->tableName(), ['type_id' => $this->getTypeID()]))) {
				 $table = $this->tableName();
				 $num_updated = 0;
				 foreach($policies as $policy => $policy_info) {
				 	$rel_ids = $this->getRelatedItems($policy_info['table'], ['returnAs' => 'ids', 'row_ids' => [$row_id]]);
				 	
				 	// TODO: take restrictToRelationshipTypes into account
				 	
				 	// Only update if date field on this has changes
				 	$spec_has_date = $date_has_changed = false;
				 	foreach($policy_info['elements'] as $dtable => $dinfo) {
				 		if ($dtable !== $table) { continue; }
				 		foreach($dinfo as $type => $dspec) {
							if (isset($dspec['date'])) {
								$spec_has_date = true;
								$element_code = array_shift(explode('.', $dspec['date']));
								if($this->elementDidChange($element_code)) {
									$date_has_changed = true;
								}
							}
						}
				 	}
				 	if ($spec_has_date && !$date_has_changed && !$this->get('deleted')) { continue; }
				 	if (!($t = Datamodel::getInstance($policy_info['table'], true))) { return null; }
				 	
				 	if ($this->inTransaction()) { $t->setTransaction($this->getTransaction()); }
				 	foreach($rel_ids as $rel_id) {
						SearchResult::clearResultCacheForRow($policy_info['table'], $rel_id);
				 		$t->deriveHistoryTrackingCurrentValue(['row_id' => $rel_id, 'omit_table_num' => ($mode == 'delete') ? $this->tableNum() : null, 'omit_row_id' => $row_id]);
				 		$num_updated++;
				 	}
 				}
 				
				if ($num_updated > 0) { ExternalCache::flush("historyTrackingContent"); }
				return true;
			}
			return false;
		}
		# ------------------------------------------------------
		/**
		 *
		 */
		static public function isHistoryTrackingCriterion($table) {
			// TODO: analyze relationships to return rel tables as criteria
			return in_array($table, ['ca_storage_locations', 'ca_occurrences', 'ca_collections', 'ca_object_lots', 'ca_loans', 'ca_movements']);
		}
		# ------------------------------------------------------
		/**
		 * Return array with list of significant events in life cycle of item
		 *
		 * @param array $pa_bundle_settings The settings for a ca_objects_history editing BUNDLES
		 * @param array $options Array of options. Options include:
		 *		noCache = Don't use any cached history data. [Default is false]
		 *		currentOnly = Only return history entries dates that include the current date. [Default is false]
		 *		limit = Only return a maximum number of history entries. [Default is null; no limit]
		 *      showChildHistory = [Default is false]
		 *		row_id = 
		 *
		 * @return array A list of life cycle events, indexed by historic timestamp for date of occurrrence. Each list value is an array of history entries.
		 */
		public function getHistory($options=null) {
			global $g_ui_locale;
			if(!is_array($options)) { $options = []; }
		
			$policy = caGetOption('policy', $options, $this->getDefaultHistoryTrackingCurrentValuePolicy());
			if ($policy && !is_array($pa_bundle_settings = caGetOption('settings', $options, null))) {
				$pa_bundle_settings = self::policy2bundleconfig(['policy' => $policy]);
			}
			if(!is_array($pa_bundle_settings)) { $pa_bundle_settings = []; }

			$pa_bundle_settings = $this->_processHistoryBundleSettings($pa_bundle_settings);
	
			$row_id = caGetOption('row_id', $options, $this->getPrimaryKey());
			$vs_cache_key = caMakeCacheKeyFromOptions(array_merge($pa_bundle_settings, $options, ['id' => $row_id]));
		
			$pb_no_cache 				= caGetOption('noCache', $options, false);
			
			// TODO: deal with proper clearing of cache
			//if (!$pb_no_cache && ExternalCache::contains($vs_cache_key, "historyTrackingContent")) { return ExternalCache::fetch($vs_cache_key, "historyTrackingContent"); }
		
			$pb_display_label_only 		= caGetOption('displayLabelOnly', $options, false);
		
			$pb_get_current_only 		= caGetOption('currentOnly', $options, false);
			$pn_limit 					= caGetOption('limit', $options, null);
		
			$vs_display_template		= caGetOption('display_template', $pa_bundle_settings, _t('No template defined'));
			$vs_history_template		= caGetOption('history_template', $pa_bundle_settings, $vs_display_template);
		
			$pb_show_child_history 		= caGetOption('showChildHistory', $options, false);
		
			$vn_current_date = TimeExpressionParser::now();

			$o_media_coder = new MediaInfoCoder();
			
			$table = $this->tableName();
			$table_num = $this->tableNum();
			$pk = $this->primaryKey();
			
			$qr = caMakeSearchResult($table, [$row_id], ['transaction' => $this->getTransaction()]);
			$qr->nextHit();
				
	//
	// Get history
	//
			$va_history = [];
		
			// Lots
			$linking_table = null;
			if (is_array($path = Datamodel::getPath($table, 'ca_object_lots')) && (((sizeof($path) == 2) && ($vn_lot_id = $qr->get('lot_id'))) || ((sizeof($path) == 3) && ($path = array_keys($path)) && ($linking_table = $path[1])))) {
				
				if ($linking_table) {
					$va_lots = $qr->get("{$linking_table}.relation_id", array('returnAsArray' => true));
				} else {
					$va_lots = [$vn_lot_id];
				}
				if(is_array($va_lot_types = caGetOption('ca_object_lots_showTypes', $pa_bundle_settings, null))) {
					require_once(__CA_MODELS_DIR__."/ca_object_lots.php");
			
					if(caGetOption('ca_object_lots_includeFromChildren', $pa_bundle_settings, false)) {
						$va_child_lots = $qr->get('ca_object_lots.lot_id', ['returnAsArray' => true]);
						if ($pb_show_child_history) { $va_lots = array_merge($va_lots, $va_child_lots); }
					}
					
					if($linking_table) {
						$qr_lots = caMakeSearchResult($linking_table, $va_lots);
					} else {
						$qr_lots = caMakeSearchResult('ca_object_lots', $va_lots);
					}
					
					$t_lot = new ca_object_lots();
					$va_lot_type_info = $t_lot->getTypeList(); 
					
					$vs_default_display_template = '^ca_object_lots.preferred_labels.name (^ca_object_lots.idno_stub)';
								
					$lots_table_num = Datamodel::getTableNum('ca_object_lots');
					$rel_table_num = $linking_table ? Datamodel::getTableNum($linking_table): $lots_table_num;
			
					while($qr_lots->nextHit()) {
						if ((string)$qr_lots->get('ca_object_lots.deleted') !== '0') { continue; }	// filter out deleted
						
						$vn_type_id = $qr_lots->get('ca_object_lots.type_id');
						$vs_display_template = $pb_display_label_only ? "" : caGetOption("ca_object_lots_{$va_lot_type_info[$vn_type_id]['idno']}_displayTemplate", $pa_bundle_settings, $vs_default_display_template);
					
						$vs_color = $va_lot_type_info[$vn_type_id]['color'];
						if (!$vs_color || ($vs_color == '000000')) {
							$vs_color = caGetOption("ca_object_lots_{$va_lot_type_info[$vn_type_id]['idno']}_color", $pa_bundle_settings, 'ffffff');
						}
						$vs_color = str_replace("#", "", $vs_color);
						$va_dates = [];
						$va_date_elements = caGetOption("ca_object_lots_{$va_lot_type_info[$vn_type_id]['idno']}_dateElement", $pa_bundle_settings, null);
			   
						if (!is_array($va_date_elements) && $va_date_elements) { $va_date_elements = [$va_date_elements]; }
		
						if (is_array($va_date_elements) && sizeof($va_date_elements)) {
							foreach($va_date_elements as $vs_date_element) {
								$va_date_bits = explode('.', $vs_date_element);
								$vs_date_spec = (Datamodel::tableExists($va_date_bits[0])) ? $vs_date_element : "ca_object_lots.{$vs_date_element}";
								$va_dates[] = [
									'sortable' => $qr_lots->get($vs_date_spec, array('sortable' => true)),
									'bounds' => explode("/", $qr_lots->get($vs_date_spec, array('sortable' => true))),
									'display' => $qr_lots->get($vs_date_spec)
								];
							}
						}
						if (!sizeof($va_dates)) {
							$va_dates[] = [
								'sortable' => $vn_date = caUnixTimestampToHistoricTimestamps($qr_lots->getCreationTimestamp(null, array('timestampOnly' => true))),
								'bounds' => array(0, $vn_date),
								'display' => caGetLocalizedDate($vn_date)
							];
						}
							
						$vn_lot_id = $qr_lots->get('ca_object_lots.lot_id');
						$relation_id = $linking_table ? $qr_lots->get("{$linking_table}.relation_id") : $vn_lot_id;
		
						foreach($va_dates as $va_date) {
							if (!$va_date['sortable']) { continue; }
							if (!in_array($vn_type_id, $va_lot_types)) { continue; }
							if ($pb_get_current_only && (($va_date['bounds'][0] > $vn_current_date))) { continue; }
							
							$o_media_coder->setMedia($va_lot_type_info[$vn_type_id]['icon']);
							$va_history[$va_date['sortable']][] = [
								'type' => 'ca_object_lots',
								'id' => $vn_lot_id,
								'display' => $qr_lots->getWithTemplate($vs_display_template),
								'color' => $vs_color,
								'icon_url' => $vs_icon_url = $o_media_coder->getMediaTag('icon'),
								'typename_singular' => $vs_typename = $va_lot_type_info[$vn_type_id]['name_singular'],
								'typename_plural' => $va_lot_type_info[$vn_type_id]['name_plural'],
								'type_id' => $vn_type_id,
								'icon' => '<div class="caUseHistoryIconContainer" style="background-color: #'.$vs_color.'"><div class="caUseHistoryIcon">'.($vs_icon_url ? $vs_icon_url : '<div class="caUseHistoryIconText">'.$vs_typename.'</div>').'</div></div>',
								'date' => $va_date['display'],
					
								'table_num' => $table_num,
								'row_id' => $row_id,
								'current_table_num' => $lots_table_num,
								'current_row_id' => $vn_lot_id,
								'tracked_table_num' => $rel_table_num,
								'tracked_row_id' => $relation_id
							];
						}
					}
				}
			}
		
			// Loans
			if (is_array($path = Datamodel::getPath($table, 'ca_loans')) && (sizeof($path) == 3) && ($path = array_keys($path)) && ($linking_table = $path[1])) {
				$va_loans = $qr->get("{$linking_table}.relation_id", array('returnAsArray' => true));
				$va_child_loans = [];
				if(caGetOption('ca_loans_includeFromChildren', $pa_bundle_settings, false)) {
					$va_child_loans = array_reduce($qr->getWithTemplate("<unit relativeTo='{$table}.children' delimiter=';'>^{$linking_table}.relation_id</unit>", ['returnAsArray' => true]), function($c, $i) { return array_merge($c, explode(';', $i)); }, []);
					if ($pb_show_child_history) { $va_loans = array_merge($va_loans, $va_child_loans); }
				}
				if(is_array($va_loan_types = caGetOption('ca_loans_showTypes', $pa_bundle_settings, null)) && is_array($va_loans)) {	
					$qr_loans = caMakeSearchResult($linking_table, $va_loans);
					require_once(__CA_MODELS_DIR__."/ca_loans.php");
					$t_loan = new ca_loans();
					$va_loan_type_info = $t_loan->getTypeList(); 
			
					$va_date_elements_by_type = [];
					foreach($va_loan_types as $vn_type_id) {
						if (!is_array($va_date_elements = caGetOption("ca_loans_{$va_loan_type_info[$vn_type_id]['idno']}_dateElement", $pa_bundle_settings, null)) && $va_date_elements) {
							$va_date_elements = [$va_date_elements];
						}
						if (!$va_date_elements) { continue; }
						$va_date_elements_by_type[$vn_type_id] = $va_date_elements;
					}
					
					$vs_default_display_template = '^ca_loans.preferred_labels.name (^ca_loans.idno)';
					
					$loan_table_num = Datamodel::getTableNum('ca_loans');
					$rel_table_num = Datamodel::getTableNum($linking_table);
		
					while($qr_loans->nextHit()) {
						if ((string)$qr_loans->get('ca_loans.deleted') !== '0') { continue; }	// filter out deleted
						
						$vn_rel_row_id = $qr_loans->get("{$linking_table}.{$pk}");
						$vn_loan_id = $qr_loans->get('ca_loans.loan_id');
						$relation_id = $qr_loans->get("{$linking_table}.relation_id");
						$vn_type_id = $qr_loans->get('ca_loans.type_id');
						$vn_rel_type_id = $qr_loans->get("{$linking_table}.type_id");
						
						$vs_display_template = $pb_display_label_only ? $vs_default_display_template : caGetOption("ca_loans_{$va_loan_type_info[$vn_type_id]['idno']}_displayTemplate", $pa_bundle_settings, $vs_default_display_template);
				
						$va_dates = [];
						if (is_array($va_date_elements_by_type[$vn_type_id]) && sizeof($va_date_elements_by_type[$vn_type_id])) {
							foreach($va_date_elements_by_type[$vn_type_id] as $vs_date_element) {
								$va_date_bits = explode('.', $vs_date_element);
								$vs_date_spec = (Datamodel::tableExists($va_date_bits[0])) ? $vs_date_element : "ca_loans.{$vs_date_element}";
								$va_dates[] = array(
									'sortable' => $qr_loans->get($vs_date_spec, array('sortable' => true)),
									'bounds' => explode("/", $qr_loans->get($vs_date_spec, array('sortable' => true))),
									'display' => $qr_loans->get($vs_date_spec)
								);
							}
						}
						if (!sizeof($va_dates)) {
							$va_dates[] = array(
								'sortable' => $vn_date = caUnixTimestampToHistoricTimestamps($qr_loans->get('lastModified.direct')),
								'bounds' => array(0, $vn_date),
								'display' => caGetLocalizedDate($vn_date)
							);
						}
				
						foreach($va_dates as $va_date) {
							if (!$va_date['sortable']) { continue; }
							if (!in_array($vn_type_id, $va_loan_types)) { continue; }
							if ($pb_get_current_only && (($va_date['bounds'][0] > $vn_current_date))) { continue; }
					
							$vs_color = $va_loan_type_info[$vn_type_id]['color'];
							if (!$vs_color || ($vs_color == '000000')) {
								$vs_color = caGetOption("ca_loans_{$va_loan_type_info[$vn_type_id]['idno']}_color", $pa_bundle_settings, 'ffffff');
							}
							$vs_color = str_replace("#", "", $vs_color);
					
							$o_media_coder->setMedia($va_loan_type_info[$vn_type_id]['icon']);
							$va_history[$va_date['sortable']][] = array(
								'type' => 'ca_loans',
								'id' => $vn_loan_id,
								'display' => $qr_loans->getWithTemplate(($vn_rel_row_id != $row_id) ? $vs_child_display_template : $vs_display_template),
								'color' => $vs_color,
								'icon_url' => $vs_icon_url = $o_media_coder->getMediaTag('icon'),
								'typename_singular' => $vs_typename = $va_loan_type_info[$vn_type_id]['name_singular'],
								'typename_plural' => $va_loan_type_info[$vn_type_id]['name_plural'],
								'type_id' => $vn_type_id,
								'icon' => '<div class="caUseHistoryIconContainer" style="background-color: #'.$vs_color.'"><div class="caUseHistoryIcon">'.($vs_icon_url ? $vs_icon_url : '<div class="caUseHistoryIconText">'.$vs_typename.'</div>').'</div></div>',
								'date' => $va_date['display'],
								'hasChildren' => sizeof($va_child_loans) ? 1 : 0,
						
								'table_num' => $table_num,
								'row_id' => $row_id,
								'current_table_num' => $loan_table_num,
								'current_row_id' => $vn_loan_id,
								'current_type_id' => $vn_type_id,
								'tracked_table_num' => $rel_table_num,
								'tracked_row_id' => $relation_id,
								'tracked_type_id' => $vn_rel_type_id
							);
						}
					}
				}
			}
		
			// Movements
			if (is_array($path = Datamodel::getPath($table, 'ca_movements')) && (sizeof($path) == 3) && ($path = array_keys($path)) && ($linking_table = $path[1])) {
				$va_movements = $qr->get("{$linking_table}.relation_id", array('returnAsArray' => true));
				$va_child_movements = [];
				if(caGetOption('ca_movements_includeFromChildren', $pa_bundle_settings, false)) {
					$va_child_movements = array_reduce($qr->getWithTemplate("<unit relativeTo='{$table}.children' delimiter=';'>^{$linking_table}.relation_id</unit>", ['returnAsArray' => true]), function($c, $i) { return array_merge($c, explode(';', $i)); }, []);
					if ($pb_show_child_history) { $va_movements = array_merge($va_movements, $va_child_movements); }
				}
				if(is_array($va_movement_types = caGetOption('ca_movements_showTypes', $pa_bundle_settings, null)) && is_array($va_movements)) {	
					$qr_movements = caMakeSearchResult($linking_table, $va_movements);
					require_once(__CA_MODELS_DIR__."/ca_movements.php");
					$t_movement = new ca_movements();
					$va_movement_type_info = $t_movement->getTypeList(); 
			
					$va_date_elements_by_type = [];
					foreach($va_movement_types as $vn_type_id) {
						if (!is_array($va_date_elements = caGetOption("ca_movements_{$va_movement_type_info[$vn_type_id]['idno']}_dateElement", $pa_bundle_settings, null)) && $va_date_elements) {
							$va_date_elements = [$va_date_elements];
						}
						if (!$va_date_elements) { continue; }
						$va_date_elements_by_type[$vn_type_id] = $va_date_elements;
					}
					
					$vs_default_display_template = '^ca_movements.preferred_labels.name (^ca_movements.idno)';
					
					$movement_table_num = Datamodel::getTableNum('ca_movements');
					$rel_table_num = Datamodel::getTableNum($linking_table);
			
					while($qr_movements->nextHit()) {
						if ((string)$qr_movements->get('ca_movements.deleted') !== '0') { continue; }	// filter out deleted
						
						$vn_rel_row_id = $qr_movements->get("{$linking_table}.{$pk}");
						$vn_movement_id = $qr_movements->get('ca_movements.movement_id');
						$relation_id = $qr_movements->get("{$linking_table}.relation_id");
						$vn_type_id = $qr_movements->get('ca_movements.type_id');
						$vn_rel_type_id = $qr_movements->get("{$linking_table}.type_id");
						
						$vs_display_template = $pb_display_label_only ? $vs_default_display_template : caGetOption("ca_movements_{$va_movement_type_info[$vn_type_id]['idno']}_displayTemplate", $pa_bundle_settings, $vs_default_display_template);			
					
						$va_dates = [];
						if (is_array($va_date_elements_by_type[$vn_type_id]) && sizeof($va_date_elements_by_type[$vn_type_id])) {
							foreach($va_date_elements_by_type[$vn_type_id] as $vs_date_element) {
								$va_date_bits = explode('.', $vs_date_element);
								$vs_date_spec = (Datamodel::tableExists($va_date_bits[0])) ? $vs_date_element : "ca_movements.{$vs_date_element}";
								$va_dates[] = array(
									'sortable' => $qr_movements->get($vs_date_spec, array('sortable' => true)),
									'bounds' => explode("/", $qr_movements->get($vs_date_spec, array('sortable' => true))),
									'display' => $qr_movements->get($vs_date_spec)
								);
							}
						}
						if (!sizeof($va_dates)) {
							$va_dates[] = array(
								'sortable' => $vn_date = caUnixTimestampToHistoricTimestamps($qr_movements->get('lastModified.direct')),
								'bound' => array(0, $vn_date),
								'display' => caGetLocalizedDate($vn_date)
							);
						}
		
						foreach($va_dates as $va_date) {
							if (!$va_date['sortable']) { continue; }
							if (!in_array($vn_type_id, $va_movement_types)) { continue; }
							if ($pb_get_current_only && (($va_date['bounds'][0] > $vn_current_date))) { continue; }
					
							$vs_color = $va_movement_type_info[$vn_type_id]['color'];
							if (!$vs_color || ($vs_color == '000000')) {
								$vs_color = caGetOption("ca_movements_{$va_movement_type_info[$vn_type_id]['idno']}_color", $pa_bundle_settings, 'ffffff');
							}
							$vs_color = str_replace("#", "", $vs_color);
					
							$o_media_coder->setMedia($va_movement_type_info[$vn_type_id]['icon']);
							$va_history[$va_date['sortable']][] = array(
								'type' => 'ca_movements',
								'id' => $vn_movement_id,
								'display' => $qr_movements->getWithTemplate(($vn_rel_row_id != $row_id) ? $vs_child_display_template : $vs_display_template),
								'color' => $vs_color,
								'icon_url' => $vs_icon_url = $o_media_coder->getMediaTag('icon'),
								'typename_singular' => $vs_typename = $va_movement_type_info[$vn_type_id]['name_singular'],
								'typename_plural' => $va_movement_type_info[$vn_type_id]['name_plural'],
								'type_id' => $vn_type_id,
								'icon' => '<div class="caUseHistoryIconContainer" style="background-color: #'.$vs_color.'"><div class="caUseHistoryIcon">'.($vs_icon_url ? $vs_icon_url : '<div class="caUseHistoryIconText">'.$vs_typename.'</div>').'</div></div>',
								'date' => $va_date['display'],
								'hasChildren' => sizeof($va_child_movements) ? 1 : 0,
						
								'table_num' => $table_num,
								'row_id' => $row_id,
								'current_table_num' => $movement_table_num,
								'current_row_id' => $vn_movement_id,
								'current_type_id' => $vn_type_id,
								'tracked_table_num' => $rel_table_num,
								'tracked_row_id' => $relation_id,
								'tracked_type_id' => $vn_rel_type_id
							);
						}
					}
				}
			}
		
			// Occurrences
			if (is_array($path = Datamodel::getPath($table, 'ca_occurrences')) && (sizeof($path) == 3) && ($path = array_keys($path)) && ($linking_table = $path[1])) {
				$va_occurrences = $qr->get("{$linking_table}.relation_id", array('returnAsArray' => true));
				$va_child_occurrences = [];
				if(is_array($va_occurrence_types = caGetOption('ca_occurrences_showTypes', $pa_bundle_settings, null)) && is_array($va_occurrences)) {	
					require_once(__CA_MODELS_DIR__."/ca_occurrences.php");
					$t_occurrence = new ca_occurrences();
					$va_occurrence_type_info = $t_occurrence->getTypeList(); 
			
					foreach($va_occurrence_types as $vn_type_id) {
						if(caGetOption("ca_occurrences_{$va_occurrence_type_info[$vn_type_id]['idno']}_includeFromChildren", $pa_bundle_settings, false)) {
							$va_child_occurrences = array_reduce($qr->getWithTemplate("<unit relativeTo='{$table}.children' delimiter=';'>^{$linking_table}.relation_id</unit>", ['returnAsArray' => true]), function($c, $i) { return array_merge($c, explode(';', $i)); }, []);
							if ($pb_show_child_history) { $va_occurrences = array_merge($va_occurrences, $va_child_occurrences); }
						}
					}
			
					$qr_occurrences = caMakeSearchResult($linking_table, $va_occurrences);
			
					$va_date_elements_by_type = [];
					foreach($va_occurrence_types as $vn_type_id) {
						if (!is_array($va_date_elements = caGetOption("ca_occurrences_{$va_occurrence_type_info[$vn_type_id]['idno']}_dateElement", $pa_bundle_settings, null)) && $va_date_elements) {
							$va_date_elements = [$va_date_elements];
						}
						if (!$va_date_elements) { continue; }
						$va_date_elements_by_type[$vn_type_id] = $va_date_elements;
					}
					
					$vs_default_display_template = '^ca_occurrences.preferred_labels.name (^ca_occurrences.idno)';
					$vs_default_child_display_template = '^ca_occurrences.preferred_labels.name (^ca_occurrences.idno)<br/>[<em>^ca_objects.preferred_labels.name (^ca_objects.idno)</em>]';
								
					$occ_table_num = Datamodel::getTableNum('ca_occurrences');
					$rel_table_num = Datamodel::getTableNum($linking_table);
			
					while($qr_occurrences->nextHit()) {
						if ((string)$qr_occurrences->get('ca_occurrences.deleted') !== '0') { continue; }	// filter out deleted
						
						$vn_rel_row_id = $qr_occurrences->get("{$linking_table}.{$pk}");
						$vn_occurrence_id = $qr_occurrences->get('ca_occurrences.occurrence_id');
						$relation_id = $qr_occurrences->get("{$linking_table}.relation_id");
						$vn_type_id = $qr_occurrences->get('ca_occurrences.type_id');
						$vs_type_idno = $va_occurrence_type_info[$vn_type_id]['idno'];
						$vn_rel_type_id = $qr_occurrences->get("{$linking_table}.type_id");
						
						$vs_display_template = $pb_display_label_only ? $vs_default_display_template : caGetOption("ca_occurrences_{$vs_type_idno}_displayTemplate", $pa_bundle_settings, $vs_default_display_template);
						$vs_child_display_template = $pb_display_label_only ? $vs_default_child_display_template : caGetOption(["ca_occurrences_{$vs_type_idno}_childDisplayTemplate", "ca_occurrences_{$vs_type_idno}_childTemplate"], $pa_bundle_settings, $vs_display_template);
		   			
						$va_dates = [];
						if (is_array($va_date_elements_by_type[$vn_type_id]) && sizeof($va_date_elements_by_type[$vn_type_id])) {
							foreach($va_date_elements_by_type[$vn_type_id] as $vs_date_element) {
								$va_date_bits = explode('.', $vs_date_element);	
								$vs_date_spec = (Datamodel::tableExists($va_date_bits[0])) ? $vs_date_element : "ca_occurrences.{$vs_date_element}";
								$va_dates[] = array(
									'sortable' => $qr_occurrences->get($vs_date_spec, array('sortable' => true)),
									'bounds' => explode("/", $qr_occurrences->get($vs_date_spec, array('sortable' => true))),
									'display' => $qr_occurrences->get($vs_date_spec)
								);
							}
						}
						if (!sizeof($va_dates)) {
							$va_dates[] = array(
								'sortable' => $vn_date = caUnixTimestampToHistoricTimestamps($qr_occurrences->get('lastModified.direct')),
								'bounds' => array(0, $vn_date),
								'display' => caGetLocalizedDate($vn_date)
							);
						}
				
						foreach($va_dates as $va_date) {
							if (!$va_date['sortable']) { continue; }
							if (!in_array($vn_type_id, $va_occurrence_types)) { continue; }
							if ($pb_get_current_only && (($va_date['bounds'][0] > $vn_current_date) || ($va_date['bounds'][1] < $vn_current_date))) { continue; }
					
							$vs_color = $va_occurrence_type_info[$vn_type_id]['color'];
							if (!$vs_color || ($vs_color == '000000')) {
								$vs_color = caGetOption("ca_occurrences_{$va_occurrence_type_info[$vn_type_id]['idno']}_color", $pa_bundle_settings, 'ffffff');
							}
							$vs_color = str_replace("#", "", $vs_color);
					
							$o_media_coder->setMedia($va_occurrence_type_info[$vn_type_id]['icon']);
							$va_history[$va_date['sortable']][] = array(
								'type' => 'ca_occurrences',
								'id' => $vn_occurrence_id,
								'display' => $qr_occurrences->getWithTemplate(($vn_rel_row_id != $row_id) ? $vs_child_display_template : $vs_display_template),
								'color' => $vs_color,
								'icon_url' => $vs_icon_url = $o_media_coder->getMediaTag('icon'),
								'typename_singular' => $vs_typename = $va_occurrence_type_info[$vn_type_id]['name_singular'],
								'typename_plural' => $va_occurrence_type_info[$vn_type_id]['name_plural'],
								'type_id' => $vn_type_id,
								'icon' => '<div class="caUseHistoryIconContainer" style="background-color: #'.$vs_color.'"><div class="caUseHistoryIcon">'.($vs_icon_url ? $vs_icon_url : '<div class="caUseHistoryIconText">'.$vs_typename.'</div>').'</div></div>',
								'date' => $va_date['display'],
								'hasChildren' => sizeof($va_child_occurrences) ? 1 : 0,
						
								'table_num' => $table_num,
								'row_id' => $row_id,
								'current_table_num' => $occurrence_table_num,
								'current_row_id' => $vn_occurrence_id,
								'current_type_id' => $vn_type_id,
								'tracked_table_num' => $rel_table_num,
								'tracked_row_id' => $relation_id,
								'tracked_type_id' => $vn_rel_type_id
							);
						}
					}
				}
			}
			
			// entities
			if (is_array($path = Datamodel::getPath($table, 'ca_entities')) && (sizeof($path) == 3) && ($path = array_keys($path)) && ($linking_table = $path[1])) {
				$va_entities = $qr->get("{$linking_table}.relation_id", array('returnAsArray' => true));
				$va_child_entities = [];
				if(is_array($va_entity_types = caGetOption('ca_entities_showTypes', $pa_bundle_settings, null)) && is_array($va_entities)) {	
					require_once(__CA_MODELS_DIR__."/ca_entities.php");
					$t_entity = new ca_entities();
					$va_entity_type_info = $t_entity->getTypeList(); 
					$entity_table_num = $t_entity->tableNum();
			
					foreach($va_entity_types as $vn_type_id) {
						if(caGetOption("ca_entities_{$va_entity_type_info[$vn_type_id]['idno']}_includeFromChildren", $pa_bundle_settings, false)) {
							$va_child_entities = array_reduce($qr->getWithTemplate("<unit relativeTo='{$table}.children' delimiter=';'>^{$linking_table}.relation_id</unit>", ['returnAsArray' => true]), function($c, $i) { return array_merge($c, explode(';', $i)); }, []);
							if ($pb_show_child_history) { $va_entities = array_merge($va_entities, $va_child_entities); }
						}
					}
			
					$qr_entities = caMakeSearchResult($linking_table, $va_entities);
			
					$va_date_elements_by_type = [];
					foreach($va_entity_types as $vn_type_id) {
						if (!is_array($va_date_elements = caGetOption("ca_entities_{$va_entity_type_info[$vn_type_id]['idno']}_dateElement", $pa_bundle_settings, null)) && $va_date_elements) {
							$va_date_elements = [$va_date_elements];
						}
						if (!$va_date_elements) { continue; }
						$va_date_elements_by_type[$vn_type_id] = $va_date_elements;
					}
					
					$vs_default_display_template = '^ca_entities.preferred_labels.name (^ca_entities.idno)';
					$vs_default_child_display_template = '^ca_entities.preferred_labels.name (^ca_entities.idno)<br/>[<em>^ca_objects.preferred_labels.name (^ca_objects.idno)</em>]';
								
					$ent_table_num = Datamodel::getTableNum('ca_entities');
					$rel_table_num = Datamodel::getTableNum($linking_table);
			
					while($qr_entities->nextHit()) {
						if ((string)$qr_entities->get('ca_entities.deleted') !== '0') { continue; }	// filter out deleted
						
						$vn_rel_row_id = $qr_entities->get("{$linking_table}.{$pk}");
						$vn_entity_id = $qr_entities->get('ca_entities.entity_id');
						$relation_id = $qr_entities->get("{$linking_table}.relation_id");
						$vn_type_id = $qr_entities->get('ca_entities.type_id');
						$vs_type_idno = $va_entity_type_info[$vn_type_id]['idno'];
						$vn_rel_type_id = $qr_entities->get("{$linking_table}.type_id");
				
						$vs_display_template = $pb_display_label_only ? $vs_default_display_template : caGetOption("ca_entities_{$vs_type_idno}_displayTemplate", $pa_bundle_settings, $vs_default_display_template);
						$vs_child_display_template = $pb_display_label_only ? $vs_default_child_display_template : caGetOption(["ca_entities_{$vs_type_idno}_childDisplayTemplate", "ca_entities_{$vs_type_idno}_childTemplate"], $pa_bundle_settings, $vs_display_template);
		   			
						$va_dates = [];
						if (is_array($va_date_elements_by_type[$vn_type_id]) && sizeof($va_date_elements_by_type[$vn_type_id])) {
							foreach($va_date_elements_by_type[$vn_type_id] as $vs_date_element) {
								$va_date_bits = explode('.', $vs_date_element);	
								$vs_date_spec = (Datamodel::tableExists($va_date_bits[0])) ? $vs_date_element : "ca_entities.{$vs_date_element}";
								$va_dates[] = array(
									'sortable' => $qr_entities->get($vs_date_spec, array('sortable' => true)),
									'bounds' => explode("/", $qr_entities->get($vs_date_spec, array('sortable' => true))),
									'display' => $qr_entities->get($vs_date_spec)
								);
							}
						}
						if (!sizeof($va_dates)) {
							$va_dates[] = array(
								'sortable' => $vn_date = caUnixTimestampToHistoricTimestamps($qr_entities->get('lastModified.direct')),
								'bounds' => array(0, $vn_date),
								'display' => caGetLocalizedDate($vn_date)
							);
						}
				
						foreach($va_dates as $va_date) {
							if (!$va_date['sortable']) { continue; }
							if (!in_array($vn_type_id, $va_entity_types)) { continue; }
							if ($pb_get_current_only && (($va_date['bounds'][0] > $vn_current_date) || ($va_date['bounds'][1] < $vn_current_date))) { continue; }
					
							$vs_color = $va_entity_type_info[$vn_type_id]['color'];
							if (!$vs_color || ($vs_color == '000000')) {
								$vs_color = caGetOption("ca_entities_{$va_entity_type_info[$vn_type_id]['idno']}_color", $pa_bundle_settings, 'ffffff');
							}
							$vs_color = str_replace("#", "", $vs_color);
					
							$o_media_coder->setMedia($va_entity_type_info[$vn_type_id]['icon']);
							$va_history[$va_date['sortable']][] = array(
								'type' => 'ca_entities',
								'id' => $vn_entity_id,
								'display' => $qr_entities->getWithTemplate(($vn_rel_row_id != $row_id) ? $vs_child_display_template : $vs_display_template),
								'color' => $vs_color,
								'icon_url' => $vs_icon_url = $o_media_coder->getMediaTag('icon'),
								'typename_singular' => $vs_typename = $va_entity_type_info[$vn_type_id]['name_singular'],
								'typename_plural' => $va_entity_type_info[$vn_type_id]['name_plural'],
								'type_id' => $vn_type_id,
								'icon' => '<div class="caUseHistoryIconContainer" style="background-color: #'.$vs_color.'"><div class="caUseHistoryIcon">'.($vs_icon_url ? $vs_icon_url : '<div class="caUseHistoryIconText">'.$vs_typename.'</div>').'</div></div>',
								'date' => $va_date['display'],
								'hasChildren' => sizeof($va_child_entities) ? 1 : 0,
						
								'table_num' => $table_num,
								'row_id' => $row_id,
								'current_table_num' => $entity_table_num,
								'current_row_id' => $vn_entity_id,
								'current_type_id' => $vn_type_id,
								'tracked_table_num' => $rel_table_num,
								'tracked_row_id' => $relation_id,
								'tracked_type_id' => $vn_rel_type_id
							);
						}
					}
				}
			}
		
			// Collections
			if (is_array($path = Datamodel::getPath($table, 'ca_collections')) && (sizeof($path) == 3) && ($path = array_keys($path)) && ($linking_table = $path[1])) {
				$va_collections = $qr->get("{$linking_table}.relation_id", array('returnAsArray' => true));
				$va_child_collections = [];
				if(caGetOption('ca_collections_includeFromChildren', $pa_bundle_settings, false)) {
					$va_child_collections = array_reduce($qr->getWithTemplate("<unit relativeTo='{$table}.children' delimiter=';'>^{$linking_table}.relation_id</unit>", ['returnAsArray' => true]), function($c, $i) { return array_merge($c, explode(';', $i)); }, []);    
					if($pb_show_child_history) { $va_collections = array_merge($va_collections, $va_child_collections); }
				}
				if(is_array($va_collection_types = caGetOption('ca_collections_showTypes', $pa_bundle_settings, null)) && is_array($va_collections)) {	
					$qr_collections = caMakeSearchResult($linking_table, $va_collections);
					require_once(__CA_MODELS_DIR__."/ca_collections.php");
					$t_collection = new ca_collections();
					$va_collection_type_info = $t_collection->getTypeList(); 
			
					$va_date_elements_by_type = [];
					foreach($va_collection_types as $vn_type_id) {
						if (!is_array($va_date_elements = caGetOption("ca_collections_{$va_collection_type_info[$vn_type_id]['idno']}_dateElement", $pa_bundle_settings, null)) && $va_date_elements) {
							$va_date_elements = [$va_date_elements];
						}
						if (!$va_date_elements) { continue; }
						$va_date_elements_by_type[$vn_type_id] = $va_date_elements;
					}
					
					$vs_default_display_template = '^ca_collections.preferred_labels.name (^ca_collections.idno)';
					$vs_default_child_display_template = '^ca_collections.preferred_labels.name (^ca_collections.idno)<br/>[<em>^ca_objects.preferred_labels.name (^ca_objects.idno)</em>]';
							
					$collection_table_num = Datamodel::getTableNum('ca_collections');
					$rel_table_num = Datamodel::getTableNum($linking_table);
			
					while($qr_collections->nextHit()) {
						if ((string)$qr_collections->get('ca_collections.deleted') !== '0') { continue; }	// filter out deleted
						
						$vn_rel_row_id = $qr_collections->get("{$linking_table}.{$pk}");
						$vn_collection_id = $qr_collections->get('ca_collections.collection_id');
						$relation_id = $qr_collections->get("{$linking_table}.relation_id");
						$vn_type_id = $qr_collections->get('ca_collections.type_id');
						$vn_rel_type_id = $qr_collections->get("{$linking_table}.type_id");
				
						$vs_display_template = $pb_display_label_only ? $vs_default_display_template : caGetOption("ca_collections_{$va_collection_type_info[$vn_type_id]['idno']}_displayTemplate", $pa_bundle_settings, $vs_default_display_template);
						$vs_child_display_template = $pb_display_label_only ? $vs_default_child_display_template : caGetOption(['ca_collections_childDisplayTemplate', 'ca_collections_childTemplate'], $pa_bundle_settings, $vs_display_template);
		   			
						$va_dates = [];
						if (is_array($va_date_elements_by_type[$vn_type_id]) && sizeof($va_date_elements_by_type[$vn_type_id])) {
							foreach($va_date_elements_by_type[$vn_type_id] as $vs_date_element) {
								$va_date_bits = explode('.', $vs_date_element);
								$vs_date_spec = (Datamodel::tableExists($va_date_bits[0])) ? $vs_date_element : "ca_collections.{$vs_date_element}";
								$va_dates[] = array(
									'sortable' => $qr_collections->get($vs_date_spec, array('sortable' => true)),
									'bounds' => explode("/", $qr_collections->get($vs_date_spec, array('sortable' => true))),
									'display' => $qr_collections->get($vs_date_spec)
								);
							}
						}
						if (!sizeof($va_dates)) {
							$va_dates[] = array(
								'sortable' => $vn_date = caUnixTimestampToHistoricTimestamps($qr_collections->get('lastModified.direct')),
								'bounds' => array(0, $vn_date),
								'display' => caGetLocalizedDate($vn_date)
							);
						}
				
						foreach($va_dates as $va_date) {
							if (!$va_date['sortable']) { continue; }
							if (!in_array($vn_type_id, $va_collection_types)) { continue; }
							if ($pb_get_current_only && (($va_date['bounds'][0] > $vn_current_date) || ($va_date['bounds'][1] < $vn_current_date))) { continue; }
					
							$vs_color = $va_collection_type_info[$vn_type_id]['color'];
							if (!$vs_color || ($vs_color == '000000')) {
								$vs_color = caGetOption("ca_collections_{$va_collection_type_info[$vn_type_id]['idno']}_color", $pa_bundle_settings, 'ffffff');
							}
							$vs_color = str_replace("#", "", $vs_color);
					
							$o_media_coder->setMedia($va_collection_type_info[$vn_type_id]['icon']);
							$va_history[$va_date['sortable']][] = array(
								'type' => 'ca_collections',
								'id' => $vn_collection_id,
								'display' => $qr_collections->getWithTemplate(($vn_rel_row_id != $row_id) ? $vs_child_display_template : $vs_display_template),
								'color' => $vs_color,
								'icon_url' => $vs_icon_url = $o_media_coder->getMediaTag('icon'),
								'typename_singular' => $vs_typename = $va_collection_type_info[$vn_type_id]['name_singular'],
								'typename_plural' => $va_collection_type_info[$vn_type_id]['name_plural'],
								'type_id' => $vn_type_id,
								'icon' => '<div class="caUseHistoryIconContainer" style="background-color: #'.$vs_color.'"><div class="caUseHistoryIcon">'.($vs_icon_url ? $vs_icon_url : '<div class="caUseHistoryIconText">'.$vs_typename.'</div>').'</div></div>',
								'date' => $va_date['display'],
								'hasChildren' => sizeof($va_child_collections) ? 1 : 0,
						
								'table_num' => $table_num,
								'row_id' => $row_id,
								'current_table_num' => $collection_table_num,
								'current_row_id' => $vn_collection_id,
								'current_type_id' => $vn_type_id,
								'tracked_table_num' => $rel_table_num,
								'tracked_row_id' => $relation_id,
								'tracked_type_id' => $vn_rel_type_id
							);
						}
					}
				}
			}
		
			// Storage locations
			if (is_array($path = Datamodel::getPath($table, 'ca_storage_locations')) && (sizeof($path) == 3) && ($path = array_keys($path)) && ($linking_table = $path[1])) {
				$va_locations = $qr->get("{$linking_table}.relation_id", array('returnAsArray' => true));
			
				$va_child_locations = [];
				if(caGetOption('ca_storage_locations_includeFromChildren', $pa_bundle_settings, false)) {
					$va_child_locations = array_reduce($qr->getWithTemplate("<unit relativeTo='ca_objects.children' delimiter=';'>^ca_objects_x_storage_locations.relation_id</unit>", ['returnAsArray' => true]), function($c, $i) { return array_merge($c, explode(';', $i)); }, []);
					if ($pb_show_child_history) { $va_locations = array_merge($va_locations, $va_child_locations); }
				}
		
				if(is_array($va_location_types = caGetOption('ca_storage_locations_showRelationshipTypes', $pa_bundle_settings, null)) && is_array($va_locations)) {	
					require_once(__CA_MODELS_DIR__."/ca_storage_locations.php");
					$t_location = new ca_storage_locations();
					if ($this->inTransaction()) { $t_location->setTransaction($this->getTransaction()); }
					$va_location_type_info = $t_location->getTypeList(); 
			
					$vs_name_singular = $t_location->getProperty('NAME_SINGULAR');
					$vs_name_plural = $t_location->getProperty('NAME_PLURAL');
			
					$qr_locations = caMakeSearchResult($linking_table, $va_locations);
			
					$vs_default_display_template = '^ca_storage_locations.parent.preferred_labels.name ➜ ^ca_storage_locations.preferred_labels.name (^ca_storage_locations.idno)';
					$vs_default_child_display_template = '^ca_storage_locations.parent.preferred_labels.name ➜ ^ca_storage_locations.preferred_labels.name (^ca_storage_locations.idno)<br/>[<em>^ca_objects.preferred_labels.name (^ca_objects.idno)</em>]';
					$vs_display_template = $pb_display_label_only ? $vs_default_display_template : caGetOption(['ca_storage_locations_displayTemplate', 'ca_storage_locations_template'], $pa_bundle_settings, $vs_default_display_template);
					$vs_child_display_template = $pb_display_label_only ? $vs_default_child_display_template : caGetOption(['ca_storage_locations_childDisplayTemplate', 'ca_storage_locations_childTemplate'], $pa_bundle_settings, $vs_display_template);
			
					$loc_table_num = Datamodel::getTableNum('ca_storage_locations');
					$rel_table_num = Datamodel::getTableNum($linking_table);
				
					while($qr_locations->nextHit()) {
						if ((string)$qr_locations->get('ca_storage_locations.deleted') !== '0') { continue; }	// filter out deleted
					
						$vn_rel_row_id = $qr_locations->get("{$linking_table}.{$pk}");
						$vn_location_id = $qr_locations->get("{$linking_table}.location_id");
						$relation_id = $qr_locations->get("{$linking_table}.relation_id");
						$vn_rel_type_id = $qr_locations->get("{$linking_table}.type_id");
				
						$va_date = array(
							'sortable' => $qr_locations->get("{$linking_table}.effective_date", array('getDirectDate' => true)),
							'bounds' => explode("/", $qr_locations->get("{$linking_table}.effective_date", array('sortable' => true))),
							'display' => $qr_locations->get("{$linking_table}.effective_date")
						);

						if (!$va_date['sortable']) { continue; }
						if (!in_array($vn_rel_type_id = $qr_locations->get("{$linking_table}.type_id"), $va_location_types)) { continue; }
						$vn_type_id = $qr_locations->get('ca_storage_locations.type_id');
				
						if ($pb_get_current_only && (($va_date['bounds'][0] > $vn_current_date))) { continue; }
				
						$vs_color = $va_location_type_info[$vn_type_id]['color'];
						if (!$vs_color || ($vs_color == '000000')) {
							$vs_color = caGetOption("ca_storage_locations_color", $pa_bundle_settings, 'ffffff');
						}
						$vs_color = str_replace("#", "", $vs_color);
				
						$o_media_coder->setMedia($va_location_type_info[$vn_type_id]['icon']);
						$va_history[$va_date['sortable']][] = array(
							'type' => 'ca_storage_locations',
							'id' => $vn_location_id,
							'relation_id' => $relation_id,
							'display' => $qr_locations->getWithTemplate(($vn_rel_row_id != $row_id) ? $vs_child_display_template : $vs_display_template),
							'color' => $vs_color,
							'icon_url' => $vs_icon_url = $o_media_coder->getMediaTag('icon'),
							'typename_singular' => $vs_name_singular, 
							'typename_plural' => $vs_name_plural, 
							'type_id' => $vn_type_id,
							'rel_type_id' => $vn_rel_type_id,
							'icon' => '<div class="caUseHistoryIconContainer" style="background-color: #'.$vs_color.'"><div class="caUseHistoryIcon">'.($vs_icon_url ? $vs_icon_url : '<div class="caUseHistoryIconText">'.$vs_name_singular.'</div>').'</div></div>',
							'date' => $va_date['display'],
							'hasChildren' => sizeof($va_child_locations) ? 1 : 0,
						
							'table_num' => $table_num,
							'row_id' => $row_id,
							'current_table_num' => $loc_table_num,
							'current_row_id' => $vn_location_id,
							'current_type_id' => $vn_type_id,
							'tracked_table_num' => $rel_table_num,
							'tracked_row_id' => $relation_id,
							'tracked_type_id' => $vn_rel_type_id
						);
					}
				}
			}
		
			// Deaccession (for ca_objects only)
			if (($table === 'ca_objects') && ((caGetOption('showDeaccessionInformation', $pa_bundle_settings, false) || (caGetOption('deaccession_displayTemplate', $pa_bundle_settings, false))))) {
				$vs_color = caGetOption('deaccession_color', $pa_bundle_settings, 'cccccc');
				$vs_color = str_replace("#", "", $vs_color);
			
				$vn_date = $qr->get('ca_objects.deaccession_date', array('sortable'=> true));
			
				$vs_default_display_template = '^ca_objects.deaccession_notes';
				$vs_display_template = $pb_display_label_only ? $vs_default_display_template : caGetOption('deaccession_displayTemplate', $pa_bundle_settings, $vs_default_display_template);
			
				$vs_name_singular = _t('deaccession');
				$vs_name_plural = _t('deaccessions');
			
				if ($qr->get('ca_objects.is_deaccessioned') && !($pb_get_current_only && ($vn_date > $vn_current_date))) {
					$va_history[$vn_date.(int)$row_id][] = array(
						'type' => 'ca_objects_deaccession',
						'id' => $row_id,
						'display' => $qr->getWithTemplate($vs_display_template),
						'color' => $vs_color,
						'icon_url' => '',
						'typename_singular' => $vs_name_singular, 
						'typename_plural' => $vs_name_plural, 
						'type_id' => null,
						'icon' => '<div class="caUseHistoryIconContainer" style="background-color: #'.$vs_color.'"><div class="caUseHistoryIcon"><div class="caUseHistoryIconText">'.$vs_name_singular.'</div>'.'</div></div>',
						'date' => $qr->get('ca_objects.deaccession_date'),
						
						'table_num' => $table_num,
						'row_id' => $row_id,
						'current_table_num' => $table_num,
						'current_row_id' => $row_id,
						'current_type_id' => $qr->get("{$table}.type_id"),
						'tracked_table_num' => $table_num,
						'tracked_row_id' => $row_id,
						'tracked_type_id' => $qr->get("{$table}.type_id")
					);
				}
			
				// get children
				if(caGetOption(['deaccession_includeFromChildren'], $pa_bundle_settings, false)) {
					if (is_array($va_child_object_ids = $qr->get("ca_objects.children.object_id", ['returnAsArray' => true])) && sizeof($va_child_object_ids) && ($q = caMakeSearchResult('ca_objects', $va_child_object_ids))) {
						while($q->nextHit()) {
							if(!$q->get('is_deaccessioned')) { continue; }
							$vn_date = $q->get('deaccession_date', array('sortable'=> true));
							$vn_id = (int)$q->get('ca_objects.object_id');
							$va_history[$vn_date.$vn_id][] = array(
								'type' => 'ca_objects_deaccession',
								'id' => $vn_id,
								'display' => $q->getWithTemplate($vs_display_template),
								'color' => $vs_color,
								'icon_url' => '',
								'typename_singular' => $vs_name_singular, 
								'typename_plural' => $vs_name_plural, 
								'type_id' => null,
								'icon' => '<div class="caUseHistoryIconContainer" style="background-color: #'.$vs_color.'"><div class="caUseHistoryIcon"><div class="caUseHistoryIconText">'.$vs_name_singular.'</div>'.'</div></div>',
								'date' => $q->get('deaccession_date'),
						
								'table_num' => $table_num,
								'row_id' => $vn_id,
								'current_table_num' => $table_num,
								'current_row_id' => $vn_id,
								'tracked_table_num' => $table_num,
								'tracked_row_id' => $vn_id
							);    
					
						}
					}
				}
			}
		
			ksort($va_history);
			if(caGetOption('sortDirection', $pa_bundle_settings, 'DESC', ['forceUppercase' => true]) !== 'ASC') { $va_history = array_reverse($va_history); }
		
			if ($pn_limit > 0) {
				$va_history = array_slice($va_history, 0, $pn_limit);
			}
		
			ExternalCache::save($vs_cache_key, $va_history, "historyTrackingContent");
			return $va_history;
		}
		# ------------------------------------------------------
		/**
		 * Return array with list of current contents
		 *
		 * @param array $pa_bundle_settings The settings for a ca_objects_history editing BUNDLES
		 * @param array $options Array of options. Options include:
		 *		noCache = Don't use any cached history data. [Default is false]
		 *		currentOnly = Only return history entries dates that include the current date. [Default is false]
		 *		limit = Only return a maximum number of history entries. [Default is null; no limit]
		 *      showChildHistory = [Default is false]
		 *		row_id = 
		 *
		 * @return array 
		 */
		public function getContents($policy, $options=null) {
			if(!($row_id = caGetOption('row_id', $options, $this->getPrimaryKey()))) { return null; }
			if (!$policy) { if (!($policy = $this->getDefaultHistoryTrackingCurrentValuePolicy())) { return null; } }
		
			$values = ca_history_tracking_current_values::find(['policy' => $policy, 'current_table_num' => $this->tableNum(), 'current_row_id' => $row_id], ['returnAs' => 'arrays']);
		
			$ids = array_map(function($v) { return $v['row_id']; }, $values);
			$row = array_shift($values);
	
			if(!($table_name = Datamodel::getTableName($row['table_num']))) { return null; }
			return caMakeSearchResult($table_name, $ids);
		}
		# ------------------------------------------------------
		/**
		 * TODO: Deprecate
		 *
		 * Returns HTML editor form bundle for ca_objects_history (object use history bundle)
		 *
		 * @param HTTPRequest $po_request The current request
		 * @param string $ps_form_name
		 * @param string $ps_placement_code
		 * @param array $pa_bundle_settings
		 * @param array $pa_options Array of options. Options include:
		 *		noCache = Don't use any cached history data. [Default is false]
		 *		currentOnly = Only return history entries dates before or on the current date. [Default is false]
		 *		limit = Only return a maximum number of history entries. [Default is null; no limit]
		 *
		 * @return string Rendered HTML bundle
		 *
		 * @uses ca_objects::getObjectHistory()
		 */
		public function getHistoryTrackingHTMLFormBundle($po_request, $ps_form_name, $ps_placement_code, $pa_bundle_settings=null, $pa_options=null) {
			require_once(__CA_MODELS_DIR__."/ca_occurrences.php");
			require_once(__CA_MODELS_DIR__."/ca_loans_x_objects.php");
			require_once(__CA_MODELS_DIR__."/ca_objects_x_storage_locations.php");
		
			global $g_ui_locale;
		
			$o_view = new View($po_request, $po_request->getViewsDirectoryPath().'/bundles/');
		
			if(!is_array($pa_options)) { $pa_options = array(); }
		
			$vs_display_template		= caGetOption('display_template', $pa_bundle_settings, _t('No template defined'));
			$vs_history_template		= caGetOption('history_template', $pa_bundle_settings, $vs_display_template);
		
			$o_view->setVar('id_prefix', $ps_form_name);
			$o_view->setVar('placement_code', $ps_placement_code);

			$pa_bundle_settings = $this->_processHistoryBundleSettings($pa_bundle_settings);
			$o_view->setVar('settings', $pa_bundle_settings);
		
			$o_view->setVar('add_label', isset($pa_bundle_settings['add_label'][$g_ui_locale]) ? $pa_bundle_settings['add_label'][$g_ui_locale] : null);
			$o_view->setVar('t_subject', $this);
		
			//
			// Occurrence update
			//
			$t_occ = new ca_occurrences();
			$va_occ_types = $t_occ->getTypeList();
			$va_occ_types_to_show =  caGetOption('add_to_occurrence_types', $pa_bundle_settings, array(), ['castTo' => 'array']);
			foreach($va_occ_types as $vn_type_id => $va_type_info) {
				if (!in_array($vn_type_id, $va_occ_types_to_show) && !in_array($va_type_info['idno'], $va_occ_types_to_show)) { unset($va_occ_types[$vn_type_id]); }
			}
		
			$o_view->setVar('occurrence_types', $va_occ_types);
			$t_occ_rel = new ca_objects_x_occurrences();
			$o_view->setVar('occurrence_relationship_types', $t_occ_rel->getRelationshipTypes(null, null,  array_merge($pa_options, $pa_bundle_settings)));
			$o_view->setVar('occurrence_relationship_types_by_sub_type', $t_occ_rel->getRelationshipTypesBySubtype($this->tableName(), $this->get('type_id'),  array_merge($pa_options, $pa_bundle_settings)));
		
			//
			// Loan update
			//
			$t_loan_rel = new ca_loans_x_objects();
			$o_view->setVar('loan_relationship_types', $t_loan_rel->getRelationshipTypes(null, null,  array_merge($pa_options, $pa_bundle_settings)));
			$o_view->setVar('loan_relationship_types_by_sub_type', $t_loan_rel->getRelationshipTypesBySubtype($this->tableName(), $this->get('type_id'),  array_merge($pa_options, $pa_bundle_settings)));

			// Location update
			$t_location_rel = new ca_objects_x_storage_locations();
			$o_view->setVar('location_relationship_types', $t_location_rel->getRelationshipTypes(null, null,  array_merge($pa_options, $pa_bundle_settings)));
			$o_view->setVar('location_relationship_types_by_sub_type', $t_location_rel->getRelationshipTypesBySubtype($this->tableName(), $this->get('type_id'),  array_merge($pa_options, $pa_bundle_settings)));

			//
			// Location update
			//
			$o_view->setVar('mode', $vs_mode = caGetOption('locationTrackingMode', $pa_bundle_settings, 'ca_storage_locations'));
		
			switch($vs_mode) {
				case 'ca_storage_locations':
					$t_last_location = null; // TODO: Fix $this->getLastLocation(array());
				
					if (!$vs_display_template) { $vs_display_template = "<unit relativeTo='ca_storage_locations'><l>^ca_storage_locations.hierarchy.preferred_labels.name%delimiter=_➜_</l></unit> (^ca_objects_x_storage_locations.effective_date)"; }
					$o_view->setVar('current_location', $t_last_location ? $t_last_location->getWithTemplate($vs_display_template) : null);
				
					if (!$vs_history_template) { $vs_history_template = $vs_display_template; }
					$o_view->setVar('location_history', []); // TODO: fix $this->getLocationHistory(array('template' => $vs_history_template)));
				
					$o_view->setVar('location_relationship_type', $this->getAppConfig()->get('object_storage_location_tracking_relationship_type'));
					$o_view->setVar('location_change_url',  null);
					break;
				case 'ca_movements':
				default:
					$t_last_movement = $this->getLastMovement(array('dateElement' => $vs_movement_date_element = $this->getAppConfig()->get('movement_storage_location_date_element')));
				
					if (!$vs_display_template) { $vs_display_template = "<l>^ca_storage_locations.hierarchy.preferred_labels.name%delimiter=_➜_</l> (^ca_movements.{$vs_movement_date_element})"; }
					$o_view->setVar('current_location', $t_last_movement ? $t_last_movement->getWithTemplate($vs_display_template) : null);
				
					if (!$vs_history_template) { $vs_history_template = $vs_display_template; }
					$o_view->setVar('location_history', $this->getMovementHistory(array('dateElement' => $vs_movement_date_element, 'template' => $vs_history_template)));
				
					$o_view->setVar('location_relationship_type', $this->getAppConfig()->get('movement_storage_location_tracking_relationship_type'));
					$o_view->setVar('location_change_url', caNavUrl($po_request, 'editor/movements', 'MovementQuickAdd', 'Form', array('movement_id' => 0)));
					break;
			}
		
			$h = $this->getHistory(array_merge($pa_bundle_settings, $pa_options));
			$o_view->setVar('child_count', $child_count = sizeof(array_filter($h, function($v) { return sizeof(array_filter($v, function($x) { return $x['hasChildren']; })); })));
			$o_view->setVar('history', $h);
		
			return $o_view->render('ca_history_tracking.php');
		}
		# ------------------------------------------------------
		/**
		 * Returns HTML form bundle for location tracking
		 *
		 * @param HTTPRequest $po_request The current request
		 * @param string $ps_form_name
		 * @param string $ps_placement_code
		 * @param array $pa_bundle_settings
		 * @param array $pa_options Array of options. Options include:
		 *			None yet.
		 *
		 * @return string Rendered HTML bundle
		 */
		public function getHistoryTrackingCurrentValueHTMLFormBundle($po_request, $ps_form_name, $ps_placement_code, $pa_bundle_settings=null, $pa_options=null) {
			global $g_ui_locale;
		
			$o_view = new View($po_request, $po_request->getViewsDirectoryPath().'/bundles/');
		
			if(!is_array($pa_options)) { $pa_options = array(); }
		
			if (is_array($vs_display_template = caGetOption('displayTemplate', $pa_bundle_settings, _t('No template defined')))) {
				$vs_display_template = caExtractSettingValueByLocale($pa_bundle_settings, 'displayTemplate', $g_ui_locale);
			}
			if (is_array($vs_history_template = caGetOption('historyTemplate', $pa_bundle_settings, $vs_display_template))) {
				 $vs_history_template = caExtractSettingValueByLocale($pa_bundle_settings, 'historyTemplate', $g_ui_locale);
			}
		
			$o_view->setVar('id_prefix', $ps_form_name);
			$o_view->setVar('placement_code', $ps_placement_code);		// pass placement code
		
			$o_view->setVar('settings', $pa_bundle_settings);
		
			$o_view->setVar('add_label', isset($pa_bundle_settings['add_label'][$g_ui_locale]) ? $pa_bundle_settings['add_label'][$g_ui_locale] : null);
			$o_view->setVar('t_subject', $this);
		
			$o_view->setVar('mode', $vs_mode = caGetOption('locationTrackingMode', $pa_bundle_settings, 'ca_storage_locations'));
		
			switch($vs_mode) {
				case 'ca_storage_locations':
					$t_last_location = $this->getLastLocation(array());
				
					if (!$vs_display_template) { $vs_display_template = "<unit relativeTo='ca_storage_locations'><l>^ca_storage_locations.hierarchy.preferred_labels.name%delimiter=_➜_</l></unit> (^ca_objects_x_storage_locations.effective_date)"; }
					$o_view->setVar('current_location', $t_last_location ? $t_last_location->getWithTemplate($vs_display_template) : null);
				
					if (!$vs_history_template) { $vs_history_template = $vs_display_template; }
					$o_view->setVar('location_history', $h = $this->getLocationHistory(array('template' => $vs_history_template)));
				
					$o_view->setVar('location_relationship_type', $this->getAppConfig()->get('object_storage_location_tracking_relationship_type'));
					$o_view->setVar('location_change_url',  null);
					break;
				case 'ca_movements':
				default:
					$t_last_movement = $this->getLastMovement(array('dateElement' => $vs_movement_date_element = $this->getAppConfig()->get('movement_storage_location_date_element')));
				
					if (!$vs_display_template) { $vs_display_template = "<l>^ca_storage_locations.hierarchy.preferred_labels.name%delimiter=_➜_</l> (^ca_movements.{$vs_movement_date_element})"; }
					$o_view->setVar('current_location', $t_last_movement ? $t_last_movement->getWithTemplate($vs_display_template) : null);
				
					if (!$vs_history_template) { $vs_history_template = $vs_display_template; }
					$o_view->setVar('location_history', $h = $this->getMovementHistory(array('dateElement' => $vs_movement_date_element, 'template' => $vs_history_template)));
				
					$o_view->setVar('location_relationship_type', $this->getAppConfig()->get('movement_object_tracking_relationship_type'));
					$o_view->setVar('location_change_url', caNavUrl($po_request, 'editor/movements', 'MovementQuickAdd', 'Form', array('movement_id' => 0)));
					break;
			}

			return $o_view->render('ca_history_tracking_current_value.php');
		}
		# ------------------------------------------------------
		/**
		 * Returns HTML form bundle for location contents
		 *
		 * @param HTTPRequest $po_request The current request
		 * @param string $ps_form_name
		 * @param string $ps_placement_code
		 * @param array $pa_bundle_settings
		 * @param array $pa_options Array of options. Options include:
		 *			None yet.
		 *
		 * @return string Rendered HTML bundle
		 */
		public function getHistoryTrackingContentsHTMLFormBundle($po_request, $ps_form_name, $ps_placement_code, $pa_bundle_settings=null, $pa_options=null) {
			require_once(__CA_MODELS_DIR__."/ca_movements.php");
			require_once(__CA_MODELS_DIR__."/ca_movements_x_objects.php");
			require_once(__CA_MODELS_DIR__."/ca_objects_x_storage_locations.php");
			global $g_ui_locale;
			
			if (!($policy = caGetOption('policy', $pa_options, caGetOption('policy', $pa_bundle_settings, null)))) { 
				return null;
			}
			
			$o_view = new View($po_request, $po_request->getViewsDirectoryPath().'/bundles/');
		
			if(!is_array($pa_options)) { $pa_options = array(); }
		
			$vs_display_template		= caGetOption('displayTemplate', $pa_bundle_settings, _t('No template defined'));
		
			$o_view->setVar('id_prefix', $ps_form_name);
			$o_view->setVar('placement_code', $ps_placement_code);		// pass placement code
		
			$o_view->setVar('settings', $pa_bundle_settings);
		
			$o_view->setVar('add_label', isset($pa_bundle_settings['add_label'][$g_ui_locale]) ? $pa_bundle_settings['add_label'][$g_ui_locale] : null);
			$o_view->setVar('t_subject', $this);
		
			
			$o_view->setVar('qr_result', ($qr_result = $this->getContents($policy)));
			
			
			
			$o_view->setVar('t_subject_rel', new ca_storage_locations());
			
		
			return $o_view->render('ca_history_tracking_contents.php');
		}
		# ------------------------------------------------------
	}
