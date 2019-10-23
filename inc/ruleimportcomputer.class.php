<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2018 Teclib' and contributors.
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

/// OCS Rules class
class RuleImportComputer extends Rule {

   const RULE_ACTION_LINK_OR_IMPORT    = 0;
   const RULE_ACTION_LINK_OR_NO_IMPORT = 1;

   const PATTERN_ENTITY_RESTRICT       = 202;

   public $restrict_matching = Rule::AND_MATCHING;
   public $can_sort          = true;

   static $rightname         = 'rule_import';



   function getTitle() {
      return __('Rules for import and link computers');
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
         'entities_id'  => [
            'table'     => 'glpi_entities',
            'field'     => 'entities_id',
            'name'      => __('Target entity for the computer'),
            'linkfield' => 'entities_id',
            'type'      => 'dropdown'
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
         ]
      ];
      /** Seems related to OCS
      $criterias['DOMAIN']['name']               = Domain::getTypename(1);

      $criteria['IPSUBNET']['name']             = __('Subnet');

      $criteria['MACADDRESS']['name']           = __('MAC address');

      $criteria['IPADDRESS']['name']            = __('IP address');*/

      $criteria['name'] = [
         'name'            => __("Item name"),
         'allow_condition' => [
            Rule::PATTERN_IS,
            Rule::PATTERN_IS_NOT,
            Rule::PATTERN_IS_EMPTY,
            Rule::PATTERN_FIND
         ]
      ];

      /** Seems related to OCS
         $criteria['DESCRIPTION']['name']          = __('Description');*/

      $criteria['mac']['name']                  = __('MAC');

      $criteria['ip']['name']                   = __('IP');

      $criteria['serial']['name']               = __('Serial number');

      $criteria['otherserial']['name']          = __('Inventory number');

      // Model as Text to allow text criteria (contains, regex, ...)
      $criteria['model']['name']                = __('Model');

      // Manufacturer as Text to allow text criteria (contains, regex, ...)
      $criteria['manufacturer']['name']         = __('Manufacturer');

      $criteria['uuid']['name']                 = __('UUID');

      $criteria['device_id']['name']            = __('Agent device_id');

      $criteria['mskey']['name']                = __('Serial of the operating system');

      $criteria['tag']['name']                  = __('Tag');

      $criteria['osname']['name']               = __('Operating system');

      $criteria['itemtype'] =[
         'name'            => __('Item type'),
         'type'            => 'dropdown_inventory_itemtype',
         'is_global'       => false,
         'allow_condition' => [Rule::PATTERN_IS, Rule::PATTERN_IS_NOT]
      ];

      $criteria['domains_id'] = [
         'table'              => 'glpi_domains',
         'field'              => 'name',
         'name'               => __('Domain'),
         'linkfield'          => 'domain',
         'type'               => 'dropdown',
         //Means that this criterion can only be used in a global search query
         'is_global'          => true,
         //'allow_condition'] => array(Rule::PATTERN_IS, Rule::PATTERN_IS_NOT)
      ];

      $criteria['entityrestrict'] = [
         'name'            => __('Restrict search in defined entity'),
         'allow_condition' => [self::PATTERN_ENTITY_RESTRICT]
      ];

      $criteria['oscomment'] = [
         'name'            => __('Operating system').'/'.__('Comments'),
         'allow_condition' => [
            Rule::PATTERN_IS,
            Rule::PATTERN_IS_NOT,
            Rule::PATTERN_CONTAIN,
            Rule::PATTERN_NOT_CONTAIN,
            Rule::PATTERN_BEGIN,
            Rule::PATTERN_END,
            Rule::REGEX_MATCH,
            Rule::REGEX_NOT_MATCH
         ]
      ];

      return $criteria;
   }


   function getActions() {

      $actions                           = [];

      $actions['_inventory']['name']        = __('Inventory link');
      $actions['_inventory']['type']        = 'inventory_type';

      $actions['_ignore_import']['name'] = __('To be unaware of import');
      $actions['_ignore_import']['type'] = 'yesonly';

      return $actions;
   }


   static function getRuleActionValues() {

      return [self::RULE_ACTION_LINK_OR_IMPORT
                                          => __('Link if possible'),
                   self::RULE_ACTION_LINK_OR_NO_IMPORT
                                          => __('Link if possible, otherwise imports declined')];
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
            $link_array = ["0" => __('No'),
                                "1" => __('Yes if equal'),
                                "2" => __('Yes if empty')];

            Dropdown::showFromArray($name, $link_array, ['value' => $value]);
      }
      return false;
   }


   /**
    * Add more criteria specific to this type of rule
   **/
   static function addMoreCriteria() {

      return [Rule::PATTERN_FIND     => __('is already present in GLPI'),
                   Rule::PATTERN_IS_EMPTY => __('is empty in GLPI'),
                   self::PATTERN_ENTITY_RESTRICT => __('Yes')]];
   }


   /**
    * @see Rule::getAdditionalCriteriaDisplayPattern()
   **/
   function getAdditionalCriteriaDisplayPattern($ID, $condition, $pattern) {

      if ($condition == Rule::PATTERN_IS_EMPTY) {
          return __('Yes');
      }
      if ($condition == self::PATTERN_ENTITY_RESTRICT) {
          return __('Yes');
      }
      if ($condition==self::PATTERN_IS || $condition==self::PATTERN_IS_NOT) {
         $crit = $this->getCriteria($ID);
         if (isset($crit['type'])
                 && $crit['type'] == 'dropdown_itemtype') {
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
            return true;

         case Rule::PATTERN_FIND :
         case Rule::PATTERN_IS_EMPTY :
            Dropdown::showYesNo($name, 0, 0);
            return true;

         /*case Rule::PATTERN_EXISTS:
         case Rule::PATTERN_DOES_NOT_EXISTS:
         case Rule::PATTERN_FIND:
         case PluginFusioninventoryInventoryRuleImport::PATTERN_IS_EMPTY:
            Dropdown::showYesNo($name, 1, 0);
            return true;*/

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
      global $DB, $PLUGIN_HOOKS;

      $complex_criterias = [];
      $continue          = true;
      $entityRestrict    = false;
      $nb_crit_find      = 0;
      $global_criteria   = ['manufacturer', 'model', 'name', 'serial', 'otherserial', 'mac', 'ip'
         'uuid', 'device_id', 'itemtype', 'domains_id',
         'entity_restrict', 'oscomment'
      ];

      //Add plugin global criteria
      if (isset($PLUGIN_HOOKS['use_rules'])) {
         foreach ($PLUGIN_HOOKS['use_rules'] as $plugin => $val) {
            if (!Plugin::isPluginLoaded($plugin)) {
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
                  if (isset($definition_criteria['is_global'])
                          && $definition_criteria['is_global']) {
                     $continue = false;
                  }
               } else if ($crit->fields["condition"] == Rule::PATTERN_FIND) {
                  $complex_criterias[] = $crit;
                  $nb_crit_find++;
               } else if ($crit->fields["condition"] == Rule::PATTERN_EXISTS) {
                  if (!isset($input[$crit->fields['criteria']])
                          OR empty($input[$crit->fields['criteria']])) {
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

      //If a value is missing, then there's a problem !
      if (!$continue) {
         return false;
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
         && ($itemtype_global != 0)
      ) {
         $itemtypeselected = $input['itemtype'];
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
         $itemtypeselected[] = "InventoryUnmanaged";
      }

      $found = false;
      foreach ($itemtypeselected as $itemtype) {
         $item = new $itemtype();
         $itemtable = $item->getTable();

         //Build the request to check if the asset exists in GLPI
         $where_entity = [];
         if (is_array($input['entities_id'])) {
            $where_entity = $input['entities_id'];
         } else {
            $where_entity = [$input['entities_id']];
         }

         $it_criteria = [
            'SELECT' => 'glpi_computers.id',
            'FROM'   => '', //to fill
            'WHERE'  => [], //to fill
            'ORDER'  => 'glpi_computers.is_deleted ASC'
         ];

         /*if ($is_ip) {
         } else if ($is_mac) {
            $sql_from .= " LEFT JOIN `glpi_networkports`
                              ON (`[typetable]`.`id` = `glpi_networkports`.`items_id`
                                 AND `glpi_networkports`.`itemtype` = '[typename]')";
         }*/

         foreach ($complex_criterias as $criteria) {
            switch ($criteria->fields['criteria']) {
               case 'name' :
                  if ($criteria->fields['condition'] == Rule::PATTERN_IS_EMPTY) {
                     $it_criteria['WHERE']['OR'] = [
                        ["$itemtable.name" => ''],
                        ["$itemtable.name"   => null]
                     ];
                  } else {
                     $it_criteria['WHERE'][] = ["$itemtable.name" => $input['name']];
                  }
                  break;

               case 'mac':
                  $it_criteria['LEFT JOIN']['glpi_networkports'] = [
                     'ON'  => [
                        $itemtable           => 'id',
                        'glpi_networkports'  => 'items_id', [
                           'glpi_networkports.itemtype' => $itemtype
                        ]
                     ]
                  ];

                  if (!is_array($input['mac'])) {
                     $input['mac'] = [$input['mac']];
                  }
                  $it_criteria['WHERE'][] = [
                     'glpi_networkports.mac' => $input['mac']
                  ];
                  break;

               case 'ip':
                  $it_criteria['LEFT JOIN']['glpi_networkports'] = [
                     'ON'  = [
                        $itemtable           => 'id',
                        'glpi_networkports'  => 'items_id', [
                           'glpi_networkports.itemtype' => $itemtype
                        ]
                     ]
                  ];
                  $it_criteria['LEFT JOIN']['glpi_networknames'] = [
                     'ON'  => [
                        'glpi_networkports'  => 'id',
                        'glpi_networknames'  => 'items_id', [
                           'glpi_networknames.itemtype' => 'NetworkPort'
                        ]
                     ]
                  ];
                  $it_criteria['LEFT JOIN']['glpi_ipaddresses'] = [
                     'ON'  => [
                        'glpi_networknames'  => 'id',
                        'glpi_ipaddresses'   => 'items_id', [
                           'glpi_ipaddresses.itemtype' => 'NetworkName'
                        ]
                     ]
                  ];

                  if (!is_array($input['ip'])) {
                     $input['ip'] = [$input['ip']];
                  }
                  $it_criteria['WHERE'][] = ['ip' => $input['ip']];
                  break;

               case 'serial' :
                  $serial = $input['serial'];

                  if (isset($input['itemtype'])
                     && $input['itemtype'] == 'Monitor'
                     /*&& $pfConfig->getValue('import_monitor_on_partial_sn') == 1*/
                     && strlen($input["serial"]) >= 4
                  ) {
                     $serial = ['LIKE', '%'.$input['serial'].'%']
                  }

                  $it_criteria['WHERE'][] = ["$itemtable.serial" => $serial];

                  /*if (isset($input['itemtype'])
                        AND $input['itemtype'] == 'Computer'
                        AND isset($_SESSION["plugin_fusioninventory_manufacturerHP"])
                        AND preg_match("/^[sS]/", $input['serial'])) {

                     $serial2 = preg_replace("/^[sS]/", "", $input['serial']);
                     $sql_where_temp = " AND (`[typetable]`.`serial`='".$input["serial"]."'
                        OR `[typetable]`.`serial`='".$serial2."')";
                     $_SESSION["plugin_fusioninventory_serialHP"] = $serial2;

                  } else if (isset($input['itemtype'])
                        AND $input['itemtype'] == 'Monitor'
                        AND $pfConfig->getValue('import_monitor_on_partial_sn') == 1
                        AND strlen($input["serial"]) >= 4) {
                     // Support partial match for monitor serial
                     $sql_where_temp = " AND `[typetable]`.`serial` LIKE '%".$input["serial"]."%'";
                  } else {
                     $sql_where_temp = " AND `[typetable]`.`serial`='".$input["serial"]."'";
                  }
                  $sql_where .= $sql_where_temp;*/
                  break;

               case 'otherserial':
                  if ($criteria->fields['condition'] == self::PATTERN_IS_EMPTY) {
                     $it_criteria['WHERE'][] = [
                        'OR' => [
                           ["$itemtable.otherserial" => ''],
                           ["$itemtable.otherserial" => null]
                        ]
                     ];
                  } else {
                     $it_criteria['WHERE'][] = ["$itemtable.otherserial" => $input['otherserial']];
                  }
                  $sql_where .= $sql_where_temp;
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
                  $condition = ["$itemtable.states_id" => $criteria->fields['pattern']];
                  if ($criteria->fields['condition'] == Rule::PATTERN_IS) {
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
                  $it_criteria['LEFT JOIN']['glpi_domains'] => [
                     'ON'  => [
                        'glpi_domains' => 'id',
                        $itemtable     => 'domains_id'
                     ]
                  ];
                  $it_criteria['WHERE'][] = [
                     'glpi_domains.name'  => $input['domains_id'];
                  ];
                  break;
            }
         }

         if (isset($PLUGIN_HOOKS['use_rules'])) {
            foreach ($PLUGIN_HOOKS['use_rules'] as $plugin => $val) {
               if (!Plugin::isPluginLoaded($plugin)) {
                  continue;
               }
               if (is_array($val) && in_array($this->getType(), $val)) {
                  $params      = ['where_entity' => $where_entity,
                                       'itemtype'     => $itemtype,
                                       'input'        => $input,
                                       'criteria'     => $complex_criterias,
                                       'sql_where'    => $it_criteria['WHERE'],
                                       'sql_from'     => $it_criteria['FROM']];
                  $sql_results = Plugin::doOneHook($plugin, "ruleImportComputer_getSqlRestriction",
                                                   $params);
                  $it_criteria = array_merge_recursive($it_criteria, $sql_results);
               }
            }
         }

         $result_glpi = $DB->request($it_criteria);

         if (count($result_glpi)) {
            while ($data = $result_glpi->next()) {
               $this->criterias_results['found_inventories'][$itemtype][] = $data['id'];
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

      /*if (count($this->actions)) {
         foreach ($this->actions as $action) {
            $executeaction = clone $this;
            $ruleoutput    = $executeaction->executePluginsActions($action, $output, $params, $input);
            foreach ($ruleoutput as $key => $value) {
               $output[$key] = $value;
            }
         }
      }
      return $output;*/

      if (isset($params['class'])) {
         $class = $params['class'];
      } else if (isset($_SESSION['plugin_fusioninventory_classrulepassed'])) {
         $classname = $_SESSION['plugin_fusioninventory_classrulepassed'];
         $class = new $classname();
      }

      $pfRulematchedlog = new PluginFusioninventoryRulematchedlog();
      $inputrulelog = [];
      $inputrulelog['date'] = date('Y-m-d H:i:s');
      $inputrulelog['rules_id'] = $this->fields['id'];
      if (!isset($params['return'])) {
         if (isset($_SESSION['plugin_fusioninventory_classrulepassed'])) {
            $inputrulelog['method'] = $class->getMethod();
         }
         if (isset($_SESSION['plugin_fusioninventory_agents_id'])) {
            $inputrulelog['plugin_fusioninventory_agents_id'] =
                           $_SESSION['plugin_fusioninventory_agents_id'];
         }
      }

      \Toolbox::logDebug(
         self::__METHOD__ . "\n     output:\n" . print_r($output, true) . "\n     params:\n" .
         print_r($params, true) . "\n     actions:\n" . count($this->actions);
      );

      if (count($this->actions)) {
         foreach ($this->actions as $action) {
            if ($action->fields['field'] == '_inventory' || $action->fields['field'] == '_fusion') {
               \Toolbox::logDebug("action value: " . $action->fields['value']);

               if ($action->fields["value"] == self::RULE_ACTION_LINK) {
                  if (isset($this->criterias_results['found_inventories'])) {
                     foreach ($this->criterias_results['found_inventories'] as $itemtype => $inventory) {
                        $items_id = current($inventory);
                        $output['found_inventories'] = [$items_id, $itemtype];
                        if (isset($_SESSION['plugin_fusioninventory_classrulepassed'])) {
                           if (!isset($params['return'])) {
                              $inputrulelog['items_id'] = $items_id;
                              $inputrulelog['itemtype'] = $itemtype;
                              $pfRulematchedlog->add($inputrulelog);
                              $pfRulematchedlog->cleanOlddata($items_id, $itemtype);
                              $class->rulepassed($items_id, $itemtype);
                           }
                        } else {
                           $_SESSION['plugin_fusioninventory_rules_id'] = $this->fields['id'];
                           $output['action'] = self::LINK_RESULT_LINK;
                        }
                        return $output;
                     }
                  } else {
                     // Import into new equipment
                     $itemtype_found = 0;
                     if (count($this->criterias)) {
                        foreach ($this->criterias as $criterion) {
                           if ($criterion->fields['criteria'] == 'itemtype') {
                              $itemtype = $criterion->fields['pattern'];
                              if (isset($_SESSION['plugin_fusioninventory_classrulepassed'])) {
                                 if (!isset($params['return'])) {
                                    $_SESSION['plugin_fusioninventory_rules_id'] =
                                                   $this->fields['id'];
                                    $class->rulepassed("0", $itemtype);
                                 }
                                 $output['found_inventories'] = [0, $itemtype];
                              } else {
                                 $_SESSION['plugin_fusioninventory_rules_id'] =
                                         $this->fields['id'];
                                 $output['action'] = self::LINK_RESULT_CREATE;
                              }
                              return $output;
                              $itemtype_found = 1;
                           }
                        }
                     }
                     if ($itemtype_found == "0") {
                        if (isset($_SESSION['plugin_fusioninventory_classrulepassed'])) {
                           if (!isset($params['return'])) {
                              $_SESSION['plugin_fusioninventory_rules_id'] = $this->fields['id'];
                              $class->rulepassed("0", "PluginFusioninventoryUnmanaged");
                           }
                           $output['found_inventories'] = [0, "PluginFusioninventoryUnmanaged"];
                           return $output;
                        } else {
                           $_SESSION['plugin_fusioninventory_rules_id'] = $this->fields['id'];
                           $output['action'] = self::LINK_RESULT_CREATE;
                           return $output;
                        }
                     }
                  }
               } else if ($action->fields["value"] == self::RULE_ACTION_DENIED) {
                  \Toolbox::logDebug("Action denied");
                  $_SESSION['plugin_fusioninventory_rules_id'] = $this->fields['id'];
                  $output['action'] = self::LINK_RESULT_DENIED;
                  return $output;
               }
            } else if ($action->fields['field'] == '_ignore_import') {
               \Toolbox::logDebug("Import ignored");
               $_SESSION['plugin_fusioninventory_rules_id'] = $this->fields['id'];
               $output['action'] = self::LINK_RESULT_DENIED;
               return $output;
            } else {
               // no import
               \Toolbox::logDebug("No import");
               $itemtype_found = 0;
               if (count($this->criterias)) {
                  foreach ($this->criterias as $criterion) {
                     if ($criterion->fields['criteria'] == 'itemtype') {
                        $itemtype = $criterion->fields['pattern'];
                        if (isset($_SESSION['plugin_fusioninventory_classrulepassed'])) {
                           if (!isset($params['return'])) {
                              $_SESSION['plugin_fusioninventory_rules_id'] = $this->fields['id'];
                              $class->rulepassed("0", $itemtype);
                           }
                           $output['found_inventories'] = [0, $itemtype];
                           return $output;
                        } else {
                           $_SESSION['plugin_fusioninventory_rules_id'] = $this->fields['id'];
                           $output['action'] = self::LINK_RESULT_CREATE;
                           return $output;
                        }
                        $itemtype_found = 1;
                     }
                  }
               }
               if ($itemtype_found == "0") {
                  if (isset($_SESSION['plugin_fusioninventory_classrulepassed'])) {
                     if (!isset($params['return'])) {
                        $_SESSION['plugin_fusioninventory_rules_id'] = $this->fields['id'];
                        $class->rulepassed("0", "PluginFusioninventoryUnmanaged");
                     }
                     $output['found_inventories'] = [0, 'PluginFusioninventoryUnmanaged'];
                     return $output;
                  } else {
                     $_SESSION['plugin_fusioninventory_rules_id'] = $this->fields['id'];
                     $output['action'] = self::LINK_RESULT_CREATE;
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
         echo "<td colspan ='2'>".__('Entity')."</td>";
         echo "<td>";
         Dropdown::show('Entity');
         echo "</td></tr>";
      }
   }

}
