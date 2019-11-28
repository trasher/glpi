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

class RuleImportEntity extends Rule {

   // From Rule
   static $rightname = 'rule_import';
   public $can_sort  = true;

   const PATTERN_CIDR     = 333;
   const PATTERN_NOT_CIDR = 334;

   function getTitle() {
      return __('Rules for assigning an item to an entity');
   }


   /**
    * @see Rule::maxActionsCount()
   **/
   function maxActionsCount() {
      // Unlimited
      return 4;
   }

   function executeActions($output, $params, array $input = []) {

      if (count($this->actions)) {
         foreach ($this->actions as $action) {
            switch ($action->fields["action_type"]) {
               case "assign" :
                  $output[$action->fields["field"]] = $action->fields["value"];
                  break;

               case "regex_result" :
                  //Assign entity using the regex's result
                  if ($action->fields["field"] == "_affect_entity_by_tag") {
                     //Get the TAG from the regex's results
                     if (isset($this->regex_results[0])) {
                        $res = RuleAction::getRegexResultById($action->fields["value"],
                                                              $this->regex_results[0]);
                     } else {
                        $res = $action->fields["value"];
                     }
                     if ($res != null) {
                        //Get the entity associated with the TAG
                        $target_entity = Entity::getEntityIDByTag(addslashes($res));
                        if ($target_entity != '') {
                           $output["entities_id"] = $target_entity;
                        }
                     }
                  }
                  break;
            }
         }
      }
      return $output;
   }


   /**
    * @see Rule::getCriterias()
   **/
   function getCriterias() {

      static $criterias = [];

      if (count($criterias)) {
         return $criterias;
      }

      $criterias['tag']['field']     = 'name';
      $criterias['tag']['name']      = __('Inventory tag');

      $criterias['domain']['field']     = 'name';
      $criterias['domain']['name']      = __('Domain');

      $criterias['subnet']['field']     = 'name';
      $criterias['subnet']['name']      = __('Subnet');

      $criterias['ip']['field']     = 'name';
      $criterias['ip']['name']      = __('IP Address');

      $criterias['name']['field']     = 'name';
      $criterias['name']['name']      = __("Equipment name");

      $criterias['serial']['field']     = 'name';
      $criterias['serial']['name']      = __('Serial number');

      $criterias['oscomment']['field']     = 'name';
      $criterias['oscomment']['name']      = __('Operating system').'/'.__('Comments');

      $criterias['_source']['table']            = '';
      $criterias['_source']['field']            = '_source';
      $criterias['_source']['name']             = __('Source');
      $criterias['_source']['allow_condition']  = [Rule::PATTERN_IS, Rule::PATTERN_IS_NOT];

      return $criterias;
   }


   /**
    * @since 0.84
    *
    * @see Rule::displayAdditionalRuleCondition()
   **/
   function displayAdditionalRuleCondition($condition, $criteria, $name, $value, $test = false) {
      global $PLUGIN_HOOKS;

      if ($criteria['field'] == '_source') {
         $tab = ['GLPI' => __('GLPI')];
         foreach ($PLUGIN_HOOKS['import_item'] as $plug => $types) {
            if (!Plugin::isPluginLoaded($plug)) {
               continue;
            }
            $tab[$plug] = Plugin::getInfo($plug, 'name');
         }
         Dropdown::showFromArray($name, $tab);
         return true;
      }

      switch ($condition) {

         case Rule::PATTERN_FIND:
            return false;

         case Rule::PATTERN_IS_EMPTY :
            Dropdown::showYesNo($name, 0, 0);
            return true;

         case Rule::PATTERN_EXISTS:
            echo Dropdown::showYesNo($name, 1, 0);
            return true;

         case Rule::PATTERN_DOES_NOT_EXISTS:
            echo Dropdown::showYesNo($name, 1, 0);
            return true;

      }
      return false;
   }


   /**
    * @since 0.84
    *
    * @see Rule::getAdditionalCriteriaDisplayPattern()
   **/
   function getAdditionalCriteriaDisplayPattern($ID, $condition, $pattern) {

      $crit = $this->getCriteria($ID);
      if (count($crit) && $crit['field'] == '_source') {
         if ($pattern != 'GLPI') {
            $name = Plugin::getInfo($pattern, 'name');
            if (empty($name)) {
               return false;
            }
         } else {
            $name = $pattern;
         }
         return $name;
      }
   }

   /**
    * Add more criteria
    *
    * @param string $criterion
    * @return array
    */
   static function addMoreCriteria($criterion = '') {
      if ($criterion == 'ip' || $criterion == 'subnet') {
         return [
            self::PATTERN_CIDR => __('is CIDR'),
            self::PATTERN_NOT_CIDR => __('is not CIDR')
         ];
      }
      return [];
   }


   /**
    * Check the criteria
    *
    * @param object $criteria
    * @param array $input
    * @return boolean
    */
   function checkCriteria(&$criteria, &$input) {

      $res = parent::checkCriteria($criteria, $input);

      if (in_array($criteria->fields["condition"], [self::PATTERN_CIDR, self::PATTERN_NOT_CIDR])) {
         $pattern   = $criteria->fields['pattern'];
         $exploded = explode('/', $pattern);
         $subnet = ip2long($exploded[0]);
         $bits = $exploded[1] ?? null;
         $mask = -1 << (32 - $bits);
         $subnet &= $mask; // nb: in case the supplied subnet wasn't correctly aligned

         if (in_array($criteria->fields["condition"], [self::PATTERN_CIDR])) {
            $value = $this->getCriteriaValue(
               $criteria->fields["criteria"],
               $criteria->fields["condition"],
               $input[$criteria->fields["criteria"]]
            );

            if (is_array($value)) {
               foreach ($value as $ip) {
                  if (isset($ip) && $ip != '') {
                     $ip = ip2long($ip);
                     if (($ip & $mask) == $subnet) {
                        $res = true;
                        break 1;
                     }
                  }
               }
            } else {
               if (isset($value) && $value != '') {
                  $ip = ip2long($value);
                  if (($ip & $mask) == $subnet) {
                     $res = true;
                  }
               }
            }
         } else if (in_array($criteria->fields["condition"], [self::PATTERN_NOT_CIDR])) {
            $value = $this->getCriteriaValue(
               $criteria->fields["criteria"],
               $criteria->fields["condition"],
               $input[$criteria->fields["criteria"]]
            );

            if (is_array($value)) {
               $resarray = true;
               foreach ($value as $ip) {
                  if (isset($ip) && $ip != '') {
                     $ip = ip2long($ip);
                     if (($ip & $mask) == $subnet) {
                        $resarray = false;
                     }
                  }
               }
               $res = $resarray;
            } else {
               if (isset($value) && $value != '') {
                  $ip = ip2long($value);
                  if (($ip & $mask) != $subnet) {
                     $res = true;
                  }
               }
            }
         }
      }

      return $res;
   }


   /**
    * Process the rule
    *
    * @param array &$input the input data used to check criterias
    * @param array &$output the initial ouput array used to be manipulate by actions
    * @param array &$params parameters for all internal functions
    * @param array &options array options:
    *                     - only_criteria : only react on specific criteria
    *
    * @return array the output updated by actions.
    *         If rule matched add field _rule_process to return value
    */
   function process(&$input, &$output, &$params, &$options = []) {

      if ($this->validateCriterias($options)) {
         $this->regex_results     = [];
         $this->criterias_results = [];
         $input = $this->prepareInputDataForProcess($input, $params);

         if ($this->checkCriterias($input)) {
            unset($output["_no_rule_matches"]);
            $refoutput = $output;
            $output = $this->executeActions($output, $params);
            if (!isset($output['pass_rule'])) {
               $this->updateOnlyCriteria($options, $refoutput, $output);
               //Hook
               $hook_params["sub_type"] = $this->getType();
               $hook_params["ruleid"]   = $this->fields["id"];
               $hook_params["input"]    = $input;
               $hook_params["output"]   = $output;
               Plugin::doHook("rule_matched", $hook_params);
               $output["_rule_process"] = true;
            }
         }
      }
   }


   function getActions() {

      $actions                             = [];

      $actions['entities_id']['name']      = __('Entity');
      $actions['entities_id']['type']      = 'dropdown';
      $actions['entities_id']['table']     = 'glpi_entities';

      $actions['locations_id']['name']     = __('Location');
      $actions['locations_id']['type']     = 'dropdown';
      $actions['locations_id']['table']    = 'glpi_locations';

      $actions['_affect_entity_by_tag']['name'] = __('Entity from TAG');
      $actions['_affect_entity_by_tag']['type'] = 'text';
      $actions['_affect_entity_by_tag']['force_actions'] = ['regex_result'];

      $actions['_ignore_import']['name']   = __('To be unaware of import');
      $actions['_ignore_import']['type']   = 'yesonly';

      $actions['is_recursive']['name']     = __('Child entities');
      $actions['is_recursive']['type']     = 'yesno';

      $actions['groups_id_tech']['name']     = __('Group in charge of the hardware');
      $actions['groups_id_tech']['type']     = 'dropdown';
      $actions['groups_id_tech']['table']    = 'glpi_groups';

      $actions['users_id_tech']['name']     = __('Technician in charge of the hardware');
      $actions['users_id_tech']['type']     = 'dropdown_users';

      return $actions;
   }

}
