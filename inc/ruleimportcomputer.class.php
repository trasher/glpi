<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2020 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class RuleImportComputer extends Rule {

   const RULE_ACTION_LINK_OR_IMPORT    = 0;
   const RULE_ACTION_LINK_OR_NO_IMPORT = 1;
   const RULE_ACTION_DENIED            = 2;

   const PATTERN_ENTITY_RESTRICT       = 202;
   const PATTERN_NETWORK_PORT_RESTRICT = 203;
   const PATTERN_ONLY_CRITERIA_RULE    = 204;

   const LINK_RESULT_DENIED            = 0;
   const LINK_RESULT_CREATE            = 1;
   const LINK_RESULT_LINK              = 2;

   public $restrict_matching = Rule::AND_MATCHING;
   public $can_sort          = true;

   static $rightname         = 'rule_import';



   function getTitle() {
      $col = new RuleImportComputerCollection;
      return $col->getTitle();
   }


   /**
    * @see Rule::maxActionsCount()
   **/
   function maxActionsCount() {
      // Unlimited
      return 1;
   }


   public function getCriterias() {

      static $criteria = [];

      if (count($criteria)) {
         return $criteria;
      }

      $criteria = [
         'entities_id' => [
            'table'     => 'glpi_entities',
            'field'     => 'entities_id',
            'name'      => __('Target entity for the asset'),
            'linkfield' => 'entities_id',
            'type'      => 'dropdown',
            'is_global'       => false,
            'allow_condition' => [
                Rule::PATTERN_IS,
                Rule::PATTERN_IS_NOT,
                Rule::PATTERN_CONTAIN,
                Rule::PATTERN_NOT_CONTAIN,
                Rule::PATTERN_BEGIN,
                Rule::PATTERN_END,
                Rule::REGEX_MATCH,
                Rule::REGEX_NOT_MATCH
             ],
         ],
         'states_id'  => [
            'table'     => 'glpi_states',
            'field'     => 'name',
            'name'      => __('Having the status'),
            'linkfield' => 'state',
            'type'      => 'dropdown',
            //Means that this criterion can only be used in a global search query
            'is_global' => true,
            'allow_condition' => [Rule::PATTERN_IS, Rule::PATTERN_IS_NOT]
         ],
         'name' => [
            'name'            => __("Item name")
         ],
         'model' => [
            'name'            => sprintf('%s > %s', __('Asset'), _n('Model', 'Models', 1)),
         ],
         'manufacturer' => [ // Manufacturer as Text to allow text criteria (contains, regex, ...)
            'name'            => Manufacturer::getTypeName(1)
         ],
         'mac' => [
            'name'            => sprintf('%s > %s > %s', __('Asset'), NetworkPort::getTypename(1), __('MAC')),
         ],
         'ip' => [
            'name'            => sprintf('%s > %s > %s', __('Asset'), NetworkPort::getTypename(1), __('IP')),
         ],
         'ifnumber' => [
            'name'            => sprintf('%s > %s > %s', __('Asset'), NetworkPort::getTypename(1), _n('Port number', 'Ports number', 1)),
         ],
         'serial' => [
            'name'            => sprintf('%s > %s', __('Asset'), __('Serial number')),
         ],
         'uuid' => [
            'name'            => sprintf('%s > %s', __('Asset'), __('UUID')),
         ],
         'device_id' => [
            'name'            => sprintf('%s > %s', Agent::getTypeName(1), __('Device_id')),
         ],
         'mskey' => [
            'name'            => sprintf('%s > %s', __('Asset'), __('Serial of the operating system')),
         ],
         'name' => [
            'name'            => sprintf('%s > %s', __('Asset'), __('Name')),
         ],
         'tag' => [
            'name'            => sprintf('%s > %s', Agent::getTypeName(1), __('Inventory tag')),
         ],
         'osname' => [
            'name'            => sprintf('%s > %s', __('Asset'), OperatingSystem::getTypeName(1)),
         ],
         'itemtype' => [
            'name'            => sprintf('%s > %s', __('Asset'), __('Item type')),
            'type'            => 'dropdown_inventory_itemtype',
            'is_global'       => false,
            'allow_condition' => [
               Rule::PATTERN_IS,
               Rule::PATTERN_IS_NOT,
               Rule::PATTERN_EXISTS,
               Rule::PATTERN_DOES_NOT_EXISTS,
            ],
         ],
         'domains_id' => [
            'table'           => 'glpi_domains',
            'field'           => 'name',
            'name'            => sprintf('%s > %s', __('Asset'), Domain::getTypeName(1)),
            'linkfield'       => 'domain',
            'type'            => 'dropdown',
            'is_global'       => false,
         ],
         'entityrestrict' => [
            'name'            => sprintf('%s > %s', __('General'), __('Restrict search in defined entity')),
            'allow_condition' => [self::PATTERN_ENTITY_RESTRICT],
         ],
         'oscomment' => [
            'name'            => sprintf('%s > %s / %s', __('Asset'), OperatingSystem::getTypeName(1), __('Comments')),
            'allow_condition' => [
               Rule::PATTERN_IS,
               Rule::PATTERN_IS_NOT,
               Rule::PATTERN_CONTAIN,
               Rule::PATTERN_NOT_CONTAIN,
               Rule::PATTERN_BEGIN,
               Rule::PATTERN_END,
               Rule::REGEX_MATCH,
               Rule::REGEX_NOT_MATCH
            ],
         ],
         'link_criteria_port' => [
            'name'            => sprintf('%s > %s', __('General'), __('Restrict criteria to same network port')),
            'allow_condition' => [self::PATTERN_NETWORK_PORT_RESTRICT],
            'is_global'       => true
         ],
         'only_these_criteria' => [
            'name'            => sprintf('%s > %s', __('General'), __('Only criteria of this rule in data')),
            'allow_condition' => [self::PATTERN_ONLY_CRITERIA_RULE],
            'is_global'       => true
         ],

      ];

      return $criteria;
   }


   function getActions() {
      $actions = [
         '_inventory'   => [
            'name'   => __('Inventory link'),
            'type'   => 'inventory_type'
         ],
         '_ignore_import'  => [
            'name'   => __('To be unaware of import'),
            'type'   => 'yesonly'
         ]
      ];
      return $actions;
   }


   static function getRuleActionValues() {
      return [
         self::RULE_ACTION_LINK_OR_IMPORT    => __('Link if possible'),
         self::RULE_ACTION_LINK_OR_NO_IMPORT => __('Link if possible, otherwise imports declined'),
         self::RULE_ACTION_DENIED            => __('Import denied (no log)')
      ];
   }


   /**
    * Add more action values specific to this type of rule
    *
    * @see Rule::displayAdditionRuleActionValue()
    *
    * @param value the value for this action
    *
    * @return the label's value or ''
   **/
   function displayAdditionRuleActionValue($value) {

      $values = self::getRuleActionValues();
      if (isset($values[$value])) {
         return $values[$value];
      }
      return '';
   }


   /**
    * @param $criteria
    * @param $name
    * @param $value
   **/
   function manageSpecificCriteriaValues($criteria, $name, $value) {

      switch ($criteria['type']) {
         case "state" :
            $link_array = [
               "0" => __('No'),
               "1" => __('Yes if equal'),
               "2" => __('Yes if empty')
            ];

            Dropdown::showFromArray($name, $link_array, ['value' => $value]);
      }
      return false;
   }


   /**
    * Add more criteria
    *
    * @param string $criterion
    * @return array
    */
   static function addMoreCriteria($criterion = '') {
      switch ($criterion) {
         case 'entityrestrict':
            return [self::PATTERN_ENTITY_RESTRICT => __('Yes')];
         case 'link_criteria_port':
            return [self::PATTERN_NETWORK_PORT_RESTRICT => __('Yes')];
         case 'only_these_criteria':
            return [self::PATTERN_ONLY_CRITERIA_RULE => __('Yes')];
         default:
            return [
               self::PATTERN_FIND      => __('is already present'),
               self::PATTERN_IS_EMPTY  => __('is empty')
            ];
      }
   }


   function getAdditionalCriteriaDisplayPattern($ID, $condition, $pattern) {

      if ($condition == self::PATTERN_IS_EMPTY
            || $condition == self::PATTERN_ENTITY_RESTRICT
            || $condition == self::PATTERN_NETWORK_PORT_RESTRICT
            || $condition == self::PATTERN_ONLY_CRITERIA_RULE) {
          return __('Yes');
      }
      if ($condition==self::PATTERN_IS || $condition==self::PATTERN_IS_NOT) {
         $crit = $this->getCriteria($ID);
         if (isset($crit['type'])
                 && $crit['type'] == 'dropdown_inventory_itemtype') {
            $array = $this->getItemTypesForRules();
            return $array[$pattern];
         }
      }
      return false;
   }


   /**
    * @see Rule::displayAdditionalRuleCondition()
   **/
   function displayAdditionalRuleCondition($condition, $criteria, $name, $value, $test = false) {

      if ($test) {
         return false;
      }

      switch ($condition) {
         case self::PATTERN_ENTITY_RESTRICT:
         case self::PATTERN_NETWORK_PORT_RESTRICT:
            return true;

         case Rule::PATTERN_FIND :
         case Rule::PATTERN_IS_EMPTY :
            Dropdown::showYesNo($name, 0, 0);
            return true;

         case Rule::PATTERN_EXISTS:
         case Rule::PATTERN_DOES_NOT_EXISTS:
         case Rule::PATTERN_FIND:
         case RuleImportComputer::PATTERN_IS_EMPTY:
            Dropdown::showYesNo($name, 1, 0);
            return true;

      }

      return false;
   }


   /**
    * @see Rule::displayAdditionalRuleAction()
   **/
   function displayAdditionalRuleAction(array $action, $value = '') {

      switch ($action['type']) {
         case 'inventory_type' :
         case 'fusion_type' :
            Dropdown::showFromArray('value', self::getRuleActionValues());
            return true;
      }
      return false;
   }


   /**
    * @param $ID
   **/
   function getCriteriaByID($ID) {

      $criteria = [];
      foreach ($this->criterias as $criterion) {
         if ($ID == $criterion->fields['criteria']) {
            $criteria[] = $criterion;
         }
      }
      return $criteria;
   }


   /**
    * @see Rule::findWithGlobalCriteria()
   **/
   function findWithGlobalCriteria($input) {
      global $DB, $PLUGIN_HOOKS, $CFG_GLPI;

      $complex_criterias = [];
      $linkCriteriaPort = false;

      $entityRestrict    = false;
      $only_these_criteria_in_data = false;
      $nb_crit_find      = 0;
      $global_criteria   = $this->getGlobalCriteria();

      //Add plugin global criteria
      if (isset($PLUGIN_HOOKS['use_rules'])) {
         foreach ($PLUGIN_HOOKS['use_rules'] as $plugin => $val) {
            if (!Plugin::isPluginActive($plugin)) {
               continue;
            }
            if (is_array($val) && in_array($this->getType(), $val)) {
               $global_criteria = Plugin::doOneHook($plugin, "ruleImportComputer_addGlobalCriteria",
                                                    $global_criteria);
            }
         }
      }

      foreach ($global_criteria as $criterion) {
         $criteria = $this->getCriteriaByID($criterion);
         if (!empty($criteria)) {
            foreach ($criteria as $crit) {
               if (!isset($input[$criterion]) || $input[$criterion] == '') {
                  $definition_criteria = $this->getCriteria($crit->fields['criteria']);
                  if ($crit->fields["criteria"] == 'link_criteria_port') {
                     $linkCriteriaPort = true;
                  } else if ($crit->fields["criteria"] == 'only_these_criteria') {
                     $only_these_criteria_in_data = true;
                  } else if (isset($definition_criteria['is_global'])
                          && $definition_criteria['is_global']) {
                     //If a value is missing, then there's a problem !
                     //TODO: add log, this breaks the process
                     return false;
                  }
               } else if ($crit->fields["condition"] == Rule::PATTERN_FIND) {
                  $complex_criterias[] = $crit;
                  $nb_crit_find++;
               } else if ($crit->fields["condition"] == Rule::PATTERN_EXISTS) {
                  if (!isset($input[$crit->fields['criteria']])
                          || empty($input[$crit->fields['criteria']])) {
                     return false;
                  }
               } else if ($crit->fields["criteria"] == 'itemtype') {
                  $complex_criterias[] = $crit;
               } else if ($crit->fields["criteria"] == 'entityrestrict') {
                  $entityRestrict = true;
               }
            }
         }
      }

      foreach ($this->getCriteriaByID('states_id') as $crit) {
         $complex_criterias[] = $crit;
      }

      // check only_these_criteria
      if ($only_these_criteria_in_data) {
         $complex_criteria_strings = [];
         foreach ($global_criteria as $criterion) {
            $criteria = $this->getCriteriaByID($criterion);
            foreach ($criteria as $crit) {
               $complex_criteria_strings[] = $crit->fields["criteria"];
            }
         }
         foreach ($input as $key=>$crit) {
            if (!in_array($key, $complex_criteria_strings)
                  && $key != "class"
                  && !is_object($crit)) {
               return false;
            }
         }
      }

      //No complex criteria
      if (empty($complex_criterias) || $nb_crit_find == 0) {
         return true;
      }

      // Get all equipment type
      $itemtype_global = 0;
      $is_ip     = false;
      $is_mac    = false;
      foreach ($complex_criterias as $criterion) {
         if ($criterion->fields['criteria'] == "itemtype") {
            $itemtype_global++;
         }

         if ($criterion->fields['criteria'] == 'ip') {
            $is_ip = true;
         } else if ($criterion->fields['criteria'] == 'mac') {
            $is_mac = true;
         }
      }

      $itemtypeselected = [];
      if (isset($input['itemtype'])
         && (is_array($input['itemtype']))
         && ($itemtype_global > 0)
      ) {
         $itemtypeselected[] = $input['itemtype'];
      } else if (isset($input['itemtype'])
         && (!empty($input['itemtype']))
         && ($itemtype_global > 0)
      ) {
         $itemtypeselected[] = $input['itemtype'];
      } else {
         foreach ($CFG_GLPI["state_types"] as $itemtype) {
            if (class_exists($itemtype)
               && $itemtype != 'SoftwareLicense'
               && $itemtype != 'Certificate'
            ) {
               $itemtypeselected[] = $itemtype;
            }
         }
         //$itemtypeselected[] = "InventoryUnmanaged";
         $itemtypeselected[] = "Peripheral";//used for networkinventory
      }

      $found = false;
      foreach ($itemtypeselected as $itemtype) {
         $item = new $itemtype();
         $itemtable = $item->getTable();

         //Build the request to check if the asset exists in GLPI
         $where_entity = $input['entities_id'] ?? [];
         if (!empty($where_entity) && !is_array($where_entity)) {
            $where_entity = [$where_entity];
         }

         $it_criteria = [
            'SELECT' => ["$itemtable.id"],
            'FROM'   => $itemtable, //to fill
            'WHERE'  => [] //to fill
         ];

         if ($linkCriteriaPort) {
            $is_ip          = false;
            $is_networkport = false;

            foreach ($complex_criterias as $criteria) {
               if ($criteria->fields['criteria'] == 'ip') {
                  $is_ip = true;
                  break;
               } else if ($this->isNetPort($criteria->fields['criteria'])) {
                  $is_networkport = true;
               }
            }

            if ($is_ip) {
               $it_criteria['LEFT JOIN']['glpi_networkports'] = [
                  'ON'  => [
                     $itemtable           => 'id',
                     'glpi_networkports'  => 'items_id', [
                        'AND' => ['glpi_networkports.itemtype' => $itemtype]
                     ]
                  ]
               ];
               $it_criteria['LEFT JOIN']['glpi_networknames'] = [
                  'ON'  => [
                     'glpi_networkports'  => 'id',
                     'glpi_networknames'  => 'items_id', [
                        'AND' => ['glpi_networknames.itemtype' => 'NetworkPort']
                     ]
                  ]
               ];
               $it_criteria['LEFT JOIN']['glpi_ipaddresses'] = [
                  'ON'  => [
                     'glpi_networknames'  => 'id',
                     'glpi_ipaddresses'   => 'items_id', [
                        'AND' => ['glpi_ipaddresses.itemtype' => 'NetworkName']
                     ]
                  ]
               ];
            } else if ($is_networkport) {
               $it_criteria['LEFT JOIN']['glpi_networkports'] = [
                  'ON'  => [
                     $itemtable           => 'id',
                     'glpi_networkports'  => 'items_id', [
                        'AND' => ['glpi_networkports.itemtype' => $itemtype]
                     ]
                  ]
               ];
            }
         } else {
            // 1 join per criterion
            foreach ($complex_criterias as $criterion) {
               if ($criterion->fields['criteria'] == 'ip') {
                  $astable = 'networkports_' . $criterion->fields['criteria'];
                  $it_criteria['LEFT JOIN']['glpi_networkports AS ' . $astable] = [
                     'ON'  => [
                        $itemtable  => 'id',
                        $astable    => 'items_id', [
                           'AND' => [$astable . '.itemtype' => $itemtype]
                        ]
                     ]
                  ];
                  $it_criteria['LEFT JOIN']['glpi_networknames'] = [
                     'ON'  => [
                        $astable  => 'id',
                        'glpi_networknames'  => 'items_id', [
                           'AND' => ['glpi_networknames.itemtype' => 'NetworkPort']
                        ]
                     ]
                  ];
                  $it_criteria['LEFT JOIN']['glpi_ipaddresses'] = [
                     'ON'  => [
                        'glpi_networknames'  => 'id',
                        'glpi_ipaddresses'   => 'items_id', [
                           'AND' => ['glpi_ipaddresses.itemtype' => 'NetworkName']
                        ]
                     ]
                  ];
               } else if ($this->isNetPort($criterion->fields['criteria'])) {
                  $astable = 'networkports_' . $criterion->fields['criteria'];
                  $it_criteria['LEFT JOIN']['glpi_networkports AS ' . $astable] = [
                     'ON'  => [
                        $itemtable  => 'id',
                        $astable    => 'items_id', [
                           'AND' => [$astable . '.itemtype' => $itemtype]
                        ]
                     ]
                  ];
               }
            }
         }

         foreach ($complex_criterias as $criterion) {
            switch ($criterion->fields['criteria']) {
               case 'name' :
                  if ($criterion->fields['condition'] == Rule::PATTERN_IS_EMPTY) {
                     $it_criteria['WHERE']['OR'] = [
                        ["$itemtable.name" => ''],
                        ["$itemtable.name"   => null]
                     ];
                  } else {
                     $it_criteria['WHERE'][] = ["$itemtable.name" => $input['name']];
                  }
                  break;

               case 'mac':
                  $ntable = 'glpi_networkports';
                  if (!$linkCriteriaPort) {
                     $ntable = 'networkports_' . $criterion->fields['criteria'];
                     $it_criteria['SELECT'][] = $ntable . ".id AS portid_" . $criterion->fields['criteria'];
                  } else {
                     $it_criteria['SELECT'][] = 'glpi_networkports.id AS portid';
                  }

                  if (!is_array($input['mac'])) {
                     $input['mac'] = [$input['mac']];
                  }
                  $it_criteria['WHERE'][] = [
                     $ntable . '.mac' => $input['mac']
                  ];
                  break;

               case 'ip':
                  if (!is_array($input['ip'])) {
                     $input['ip'] = [$input['ip']];
                  }

                  $ntable = 'glpi_networkports';
                  if (!$linkCriteriaPort) {
                     $ntable = "networkports_".$criterion->fields['criteria'];
                     $it_criteria['SELECT'][] = $ntable.".id AS portid_".$criterion->fields['criteria'];
                  } else if (!in_array('glpi_networkports.id AS portid', $it_criteria['SELECT'])) {
                     $it_criteria['SELECT'][] = 'glpi_networkports.id AS portid';
                  }

                  $it_criteria['WHERE'][] = ['glpi_ipaddresses.name' => $input['ip']];
                  break;

               /*case 'ifdescr':
                  $ntable = 'glpi_networkports';
                  if (!$linkCriteriaPort) {
                     $ntable = "networkports_".$criterion->fields['criteria'];
                     $it_criteria['SELECT'][] = $ntable.".id AS portid_".$criterion->fields['criteria'];
                  } else if (!in_array('glpi_networkports.id AS portid', $it_criteria['SELECT'])) {
                     $it_criteria['SELECT'][] = 'glpi_networkports.id AS portid';
                  }

                  $it_criteria['WHERE'][] = [$ntable . '.ifdescr' => $input['ifdescr']];
                  break;*/

               case 'ifnumber':
                  $ntable = 'glpi_networkports';
                  if (!$linkCriteriaPort) {
                     $ntable = "networkports_".$criterion->fields['criteria'];
                     $it_criteria['SELECT'][] = $ntable.".id AS portid_".$criterion->fields['criteria'];
                  } else if (!in_array('glpi_networkports.id AS portid', $it_criteria['SELECT'])) {
                     $it_criteria['SELECT'][] = 'glpi_networkports.id AS portid';
                  }
                  $it_criteria['WHERE'][] = [$ntable . '.logical_number' => $input['ifnumber']];
                  break;

               case 'serial' :
                  $serial = $input['serial'];
                  $conf = new Glpi\Inventory\Conf();

                  if (isset($input['itemtype'])
                     && $input['itemtype'] == 'Monitor'
                     && $conf->import_monitor_on_partial_sn == true
                     && strlen($input["serial"]) >= 4
                  ) {
                     $serial = ['LIKE', '%'.$input['serial'].'%'];
                  }

                  $it_criteria['WHERE'][] = ["$itemtable.serial" => $serial];
                  break;

               case 'otherserial':
                  if ($criterion->fields['condition'] == self::PATTERN_IS_EMPTY) {
                     $it_criteria['WHERE'][] = [
                        'OR' => [
                           ["$itemtable.otherserial" => ''],
                           ["$itemtable.otherserial" => null]
                        ]
                     ];
                  } else {
                     $it_criteria['WHERE'][] = ["$itemtable.otherserial" => $input['otherserial']];
                  }
                  break;

               case 'model' :
                  // search for model, don't create it if not found
                  $modelclass = $itemtype.'Model';
                  $options    = ['manufacturer' => addslashes($input['manufacturer'])];
                  $mid        = Dropdown::importExternal($itemtype.'Model', addslashes($input['model']), -1,
                                                         $options, '', false);
                  $it_criteria['WHERE'][] = [$itemtable.'.'.$modelclass::getForeignKeyField() => $mid];
                  break;

               case 'manufacturer' :
                  // search for manufacturer, don't create it if not found
                  $mid        = Dropdown::importExternal('Manufacturer', addslashes($input['manufacturer']), -1,
                                                         [], '', false);
                  $it_criteria['WHERE'][] = ["$itemtable.manufacturers_id" => $mid];
                  break;

               case 'states_id' :
                  $condition = ["$itemtable.states_id" => $criterion->fields['pattern']];
                  if ($criterion->fields['condition'] == Rule::PATTERN_IS) {
                     $it_criteria['WHERE'][] = $condition;
                  } else {
                     $it_criteria['WHERE'][] = ['NOT' => $condition];
                  }
                  break;

               case 'uuid':
                  $it_criteria['WHERE'][] = ['uuid' => $input['uuid']];
                  break;

               case 'device_id':
                  $it_criteria['LEFT JOIN']['glpi_agents'] = [
                     'ON'  => [
                        'glpi_agents'  => 'items_id',
                        $itemtable     => 'id'
                     ]
                  ];
                  $it_criteria['WHERE'][] = [
                     'glpi_agents.device_id' => $input['device_id']
                  ];
                  break;

               case 'domains_id':
                  $it_criteria['LEFT JOIN']['glpi_domains'] = [
                     'ON'  => [
                        'glpi_domains' => 'id',
                        $itemtable     => 'domains_id'
                     ]
                  ];
                  $it_criteria['WHERE'][] = [
                     'glpi_domains.name'  => $input['domains_id']
                  ];
                  break;
            }
         }

         if (isset($PLUGIN_HOOKS['use_rules'])) {
            foreach ($PLUGIN_HOOKS['use_rules'] as $plugin => $val) {
               if (!Plugin::isPluginActive($plugin)) {
                  continue;
               }
               if (is_array($val) && in_array($this->getType(), $val)) {
                  $params      = ['where_entity' => $where_entity,
                                       'itemtype'     => $itemtype,
                                       'input'        => $input,
                                       'criteria'     => $complex_criterias,
                                       'sql_criteria' => $it_criteria,
                                       'sql_select'   => $it_criteria['SELECT'],
                                       'sql_where'    => $it_criteria['WHERE'],
                                       'sql_from'     => $it_criteria['FROM'],
                                       'sql_leftjoin' => $it_criteria['LEFT JOIN']];
                  $sql_results = Plugin::doOneHook($plugin, "ruleImportComputer_getSqlRestriction",
                                                   $params);

                  //$it_criteria = array_merge_recursive($it_criteria, $sql_results);
                  $sql_select['SELECT']        = $sql_results['sql_select'];
                  $sql_from['FROM']          = $sql_results['sql_from'];
                  $sql_where['WHERE']        = $sql_results['sql_where'];
                  $sql_leftjoin['LEFT JOIN'] = $sql_results['sql_leftjoin'];

                  $it_criteria = array_merge_recursive($it_criteria, $sql_select);
                  $it_criteria = array_merge_recursive($it_criteria, $sql_from);
                  $it_criteria = array_merge_recursive($it_criteria, $sql_leftjoin);
                  $it_criteria = array_merge_recursive($it_criteria, $sql_where);

               }
            }
         }

         $result_glpi = $DB->request($it_criteria);

         if (count($result_glpi)) {
            while ($data = $result_glpi->next()) {
               $this->criterias_results['found_inventories'][$itemtype][] = $data['id'];
               $this->criterias_results['found_port'] = 0;
               foreach ($data as $alias=>$value) {
                  if (strstr($alias, "portid")
                     && !is_null($value)
                     && is_numeric($value)
                     && $value > 0) {
                     $this->criterias_results['found_port'] = $value;
                  }
               }
            }
            $found = true;
         }
      }

      if ($found) {
         return true;
      }

      if (count($this->actions)) {
         foreach ($this->actions as $action) {
            if ($action->fields['field'] == '_inventory' || $action->fields['field'] == '_fusion') {
               if ($action->fields["value"] == self::RULE_ACTION_LINK_OR_NO_IMPORT) {
                  return true;
               }
            }
         }
      }
      return false;

   }

   function executeActions($output, $params, array $input = []) {
      $class = $params['class'];
      $rules_id = $this->fields['id'];
      $output['rules_id'] = $rules_id;

      $rulesmatched = new RuleMatchedLog();
      $inputrulelog = [
         'date'      => date('Y-m-d H:i:s'),
         'rules_id'  => $rules_id
      ];

      if (method_exists($class, 'getAgent') && $class->getAgent()) {
         $inputrulelog['agents_id'] = $class->getAgent()->fields['id'];
      }

      if (!isset($params['return'])) {
         $inputrulelog['method'] = 'inventory'; //$class->getMethod();
      }

      if (count($this->actions)) {
         foreach ($this->actions as $action) {
            if ($action->fields['field'] == '_ignore_import' || $action->fields["value"] == self::RULE_ACTION_DENIED) {
               $output['action'] = self::LINK_RESULT_DENIED;
               return $output;
            }

            if ($action->fields['field'] != '_inventory' && $action->fields['field'] != '_fusion') {
               // no import
               $itemtype_found = 0;
               if (count($this->criterias)) {
                  foreach ($this->criterias as $criterion) {
                     if ($criterion->fields['criteria'] == 'itemtype' && !is_numeric($criterion->fields['pattern'])) {
                        $itemtype = $criterion->fields['pattern'];
                        if (!isset($params['return'])) {
                           $class->rulepassed("0", $itemtype, $rules_id);
                        }
                        $output['found_inventories'] = [0, $itemtype, $rules_id];
                        return $output;
                     }
                  }
               }
               if ($itemtype_found == "0") {
                  if (!isset($params['return'])) {
                     $class->rulepassed("0", "InventoryUnmanaged", $rules_id);
                  }
                  $output['found_inventories'] = [0, 'InventoryUnmanaged', $rules_id];
                  return $output;
               }
            }

            if ($action->fields["value"] == self::RULE_ACTION_LINK_OR_IMPORT) {
               if (isset($this->criterias_results['found_inventories'])) {
                  foreach ($this->criterias_results['found_inventories'] as $itemtype => $inventory) {
                     $items_id = current($inventory);
                     $output['found_inventories'] = [$items_id, $itemtype, $rules_id];
                     if (!isset($params['return'])) {
                        $inputrulelog = $inputrulelog + [
                           'items_id'  => $items_id,
                           'itemtype'  => $itemtype
                        ];
                        $rulesmatched->add($inputrulelog);
                        $rulesmatched->cleanOlddata($items_id, $itemtype);
                        $class->rulepassed($items_id, $itemtype, $rules_id, $this->criterias_results['found_port']);
                     }
                     return $output;
                  }
               } else {
                  // Import into new equipment
                  $itemtype_found = 0;
                  if (count($this->criterias)) {
                     foreach ($this->criterias as $criterion) {
                        if ($criterion->fields['criteria'] == 'itemtype' && !is_numeric($criterion->fields['pattern'])) {
                           $itemtype = $criterion->fields['pattern'];
                           if (!isset($params['return'])) {
                              $class->rulepassed("0", $itemtype, $rules_id);
                           }
                           $output['found_inventories'] = [0, $itemtype, $rules_id];
                           return $output;
                        }
                     }
                  }
                  if ($itemtype_found == "0") {
                     if (!isset($params['return'])) {
                        $class->rulepassed("0", "InventoryUnmanaged", $rules_id);
                     }
                     $output['found_inventories'] = [0, "InventoryUnmanaged", $rules_id];
                     return $output;
                  }
               }
            }
         }
      }
      return $output;
   }

   /**
    * Function used to display type specific criterias during rule's preview
    *
    * @see Rule::showSpecificCriteriasForPreview()
   **/
   function showSpecificCriteriasForPreview($fields) {

      $entity_as_criterion = false;
      foreach ($this->criterias as $criterion) {
         if ($criterion->fields['criteria'] == 'entities_id') {
            $entity_as_criterion = true;
            break;
         }
      }
      if (!$entity_as_criterion) {
         echo "<tr class='tab_bg_1'>";
         echo "<td colspan ='2'>".Entity::getTypeName(1)."</td>";
         echo "<td>" . Dropdown::show('Entity') . "</td></tr>";
      }
   }

   /**
    * Create rules (initialisation)
    *
    *
    * @param boolean $reset        Whether to reset before adding new rules, defaults to true
    * @param boolean $with_plugins Use plugins rules or not
    * @param boolean $check        Check if rule exists before creating
    *
    * @return boolean
    */
   public static function initRules($reset = true, $with_plugins = true, $check = false) {
      global $PLUGIN_HOOKS;

      if ($reset) {
         $rule = new Rule();
         $rules = $rule->find(['sub_type' => 'RuleImportComputer']);
         foreach ($rules as $data) {
            $rule->delete($data);
         }
      }

      $rules = [];

      $rules[] = [
         'name'      => 'Device update (by mac+ifnumber restricted port)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 9,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ifnumber',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ifnumber',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'link_criteria_port',
               'condition' => 203,
               'pattern'   => 1
            ],
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Device update (by mac+ifnumber not restricted port)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 9,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ifnumber',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ifnumber',
               'condition' => 8,
               'pattern'   => 1
            ],
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Device import (by mac+ifnumber)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 9,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ifnumber',
               'condition' => 8,
               'pattern'   => 1
            ],
         ],
         'action'    => '_link'
      ];

      //Above commented rules are related to SNMP inventory
      /*$rules[] = [
         'name'      => 'Device update (by ip+ifdescr restricted port)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 9,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ip',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ip',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ifdescr',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ifdescr',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'link_criteria_port',
               'condition' => 203,
               'pattern'   => 1
            ],
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Device update (by ip+ifdescr not restricted port)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 9,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ip',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ip',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ifdescr',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ifdescr',
               'condition' => 8,
               'pattern'   => 1
            ],
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Device import (by ip+ifdescr)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 9,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ip',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'ifdescr',
               'condition' => 8,
               'pattern'   => 1
            ],
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Update only mac address (mac on switch port)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 9,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'only_these_criteria',
               'condition' => 204,
               'pattern'   => 1
            ],
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Import only mac address (mac on switch port)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 9,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'only_these_criteria',
               'condition' => 204,
               'pattern'   => 1
            ],
         ],
         'action'    => '_link'
      ];*/

      $rules[] = [
         'name'      => 'Computer constraint (name)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Computer'
            ],
            [
               'criteria'  => 'name',
               'condition' => 9,
               'pattern'   => 1
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'Computer update (by serial + uuid)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Computer'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'uuid',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'uuid',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];
      $rules[] = [
         'name'      => 'Computer update (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Computer'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Computer update (by uuid)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Computer'
            ],
            [
               'criteria'  => 'uuid',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'uuid',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Computer update (by mac)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Computer'
            ],
            [
               'criteria'  => 'mac',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Computer update (by name)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Computer'
            ],
            [
               'criteria'  => 'name',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'name',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Computer import (by serial + uuid)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Computer'
            ],
            [
               'criteria'  => 'uuid',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Computer import (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Computer'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Computer import (by uuid)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Computer'
            ],
            [
               'criteria'  => 'uuid',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Computer import (by mac)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Computer'
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Computer import (by name)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Computer'
            ],
            [
               'criteria'  => 'name',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Computer import denied',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Computer'
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'Printer constraint (name)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Printer'
            ],
            [
               'criteria'  => 'name',
               'condition' => 9,
               'pattern'   => 1
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'Printer update (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Printer'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'serial',
               'condition' => 10,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Printer update (by mac)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Printer'
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 10,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Printer import (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Printer'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Printer import (by mac)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Printer'
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Printer import denied',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Printer'
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'NetworkEquipment constraint (name)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'NetworkEquipment'
            ],
            [
               'criteria'  => 'name',
               'condition' => 9,
               'pattern'   => 1
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'NetworkEquipment update (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'NetworkEquipment'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'serial',
               'condition' => 10,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'NetworkEquipment update (by mac)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'NetworkEquipment'
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 10,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'NetworkEquipment import (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'NetworkEquipment'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'NetworkEquipment import (by mac)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'NetworkEquipment'
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'NetworkEquipment import denied',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'NetworkEquipment'
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'Peripheral update (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Peripheral'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'serial',
               'condition' => 10,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Peripheral import (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Peripheral'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Peripheral import denied',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Peripheral'
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'Monitor update (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Monitor'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'serial',
               'condition' => 10,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Monitor import (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Monitor'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Monitor import denied',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Monitor'
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'Phone constraint (name)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Phone'
            ],
            [
               'criteria'  => 'name',
               'condition' => 9,
               'pattern'   => 1
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'Phone update (by mac)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Phone'
            ],
            [
               'criteria'  => 'mac',
               'condition' => 10,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Phone import (by mac)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Phone'
            ],
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Phone import denied',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Phone'
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'Cluster update (by uuid)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Cluster'
            ],
            [
               'criteria'  => 'uuid',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'uuid',
               'condition' => 10,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Cluster import (by uuid)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Cluster'
            ],
            [
               'criteria'  => 'uuid',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Cluster import denied',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Cluster'
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'Enclosure update (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Enclosure'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'serial',
               'condition' => 10,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Enclosure import (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Enclosure'
            ],
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Enclosure import denied',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => 'Enclosure'
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'Global constraint (name)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'name',
               'condition' => 9,
               'pattern'   => 1
            ]
         ],
         'action'    => '_deny'
      ];

      $rules[] = [
         'name'      => 'Global update (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'serial',
               'condition' => 10,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Global update (by mac)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ],
            [
               'criteria'  => 'mac',
               'condition' => 10,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Global import (by serial)',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'serial',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Global import (by mac)',
         'match'     => 'AND',
         'is_active' => 0,
         'criteria'  => [
            [
               'criteria'  => 'mac',
               'condition' => 8,
               'pattern'   => 1
            ]
         ],
         'action'    => '_link'
      ];

      $rules[] = [
         'name'      => 'Global import denied',
         'match'     => 'AND',
         'is_active' => 1,
         'criteria'  => [
            [
               'criteria'  => 'itemtype',
               'condition' => 0,
               'pattern'   => ''
            ]
         ],
         'action'    => '_deny'
      ];

      //load default rules from plugins
      if ($with_plugins && isset($PLUGIN_HOOKS['add_rules'])) {
         foreach ($PLUGIN_HOOKS['add_rules'] as $plugin => $val) {
            if (!Plugin::isPluginLoaded($plugin)) {
               continue;
            }
            //FIXME: make that work, maybe not the correct hook type
            /*$rules = array_merge(
               $rules,
               Plugin::doOneHook(
                  $plugin,
                  "ruleImportComputer_addGlobalCriteria",
                  $global_criteria
               )
            );*/
         }
      }

      $ranking = 0;
      foreach ($rules as $rule) {
         $rulecollection = new RuleImportComputerCollection();
         $input = [
            'is_active' => $rule['is_active'],
            'name'      => $rule['name'],
            'match'     => $rule['match'],
            'sub_type'  => self::getType(),
            'ranking'   => $ranking
         ];

         $exists = false;
         if ($check === true) {
            $exists = $rulecollection->getFromDBByCrit($input);
         }

         if ($exists === true) {
            //rule already exists, ignore.
            continue;
         }
         $rule_id = $rulecollection->add($input);

         // Add criteria
         $rulefi = $rulecollection->getRuleClass();
         foreach ($rule['criteria'] as $criteria) {
            $rulecriteria = new RuleCriteria(get_class($rulefi));
            $criteria['rules_id'] = $rule_id;
            $rulecriteria->add($criteria);
         }

         // Add action
         $ruleaction = new RuleAction(get_class($rulefi));
         $input = [
            'rules_id'     => $rule_id,
            'action_type'  => 'assign'
         ];

         switch ($rule['action']) {
            case '_link':
               $input['field'] = '_inventory';
               $input['value'] = self::RULE_ACTION_LINK_OR_IMPORT;
               break;
            case '_deny':
               $input['field'] = '_inventory';
               $input['value'] = self::RULE_ACTION_DENIED;
               break;
            case '_ignore_import':
               $input['field'] = '_ignore_import';
               $input['value'] = '1';
               break;
         }

         $ruleaction->add($input);

         $ranking++;
      }
      return true;
   }

   /**
    * Get itemtypes have state_type and unmanaged devices
    *
    * @global array $CFG_GLPI
    * @return array
    */
   static function getItemTypesForRules() {
      global $CFG_GLPI;

      $types = [];
      foreach ($CFG_GLPI["state_types"] as $itemtype) {
         if (class_exists($itemtype)) {
            $item = new $itemtype();
            $types[$itemtype] = $item->getTypeName();
         }
      }
      $types[""] = __('No itemtype defined');
      ksort($types);
      return $types;
   }

   function addSpecificParamsForPreview($params) {
      $class = new class {
         public function rulepassed($items_id, $itemtype, $rules_id) {
         }
      };
      return $params + ['class' => $class];
   }

   /**
    * Get criteria related to network ports
    *
    * @return array
    */
   public function getNetportCriteria(): array {
      return [
         'mac',
         'ip',
         'ifnumber',
         /*'ifdescr' // related to SNMP inventory */
      ];
   }

   /**
    * Get global criteria
    *
    * @return array
    */
   public function getGlobalCriteria(): array {
      return array_merge([
         'manufacturer',
         'model',
         'name',
         'serial',
         'otherserial',
         'uuid',
         'device_id',
         'itemtype',
         'domains_id',
         'entity_restrict',
         'oscomment',
         'link_criteria_port',
         'only_these_criteria'
      ], $this->getNetportCriteria());
   }

   /**
    * Check if criterion is related to network ports
    *
    * @param string $criterion Criterion to check
    *
    * @return boolean
    */
   public function isNetPort($criterion): bool {
      return in_array($criterion, $this->getNetportCriteria());
   }
}
