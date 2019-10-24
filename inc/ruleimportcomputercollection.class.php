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

/// Import rules collection class
class RuleImportComputerCollection extends RuleCollection {

   // From RuleCollection
   public $stop_on_first_match = true;
   static $rightname           = 'rule_import';
   public $menu_option         = 'linkcomputer';

   function defineTabs($options = []) {
      $ong = parent::defineTabs();

      $this->addStandardTab(__CLASS__, $ong, $options);

      return $ong;
   }

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      global $CFG_GLPI;

      if (!$withtemplate) {
         switch ($item->getType()) {
            case __CLASS__ :
               $ong    = [];
               $types = $CFG_GLPI['state_types'];
               foreach ($types as $type) {
                  if (class_exists($type)) {
                     $ong[$type] = $type::getTypeName();
                  }
               }
               $ong[] = "Peripheral";//used for networkinventory
               $ong['_global'] = __('Global');
               return $ong;
         }
      }
      return '';
   }

   function canList() {
      return static::canView();
   }


   function getTitle() {
      return __('Rules for import and link equipments');
   }


   /**
    * Get name of this rule class
    *
    * @return string
    */
   function getRuleClassName() {
      $rule_class = [];
      if (preg_match('/(.*)Collection/', get_class($this), $rule_class)) {
         return $rule_class[1];
      }
      return "";
   }


   /**
    * Get an instance of the class to manipulate rule of this collection
    *
    * @return null|object
    */
   function getRuleClass() {
      $name = $this->getRuleClassName();
      if ($name !=  '') {
         return new $name();
      } else {
         return null;
      }
   }

   public function collectionFilter($criteria) {
      //current tab
      $current_tab = str_replace(__CLASS__.'$', '', Session::getActiveTab($this->getType()));
      $tabs = $this->getTabNameForItem($this);

      if (!isset($tabs[$current_tab])) {
         return $criteria;
      }

      $criteria['LEFT JOIN']['glpi_rulecriterias AS crit'] = [
         'ON'  => [
            'crit'         => 'rules_id',
            'glpi_rules'   => 'id'
         ]
      ];
      $criteria['GROUPBY'] = ['glpi_rules.id'];

      if ($current_tab != '_global') {
         $where = [
            'crit.criteria'   => 'itemtype',
            'crit.pattern'    => getSingular($tabs[$current_tab])
         ];
         $criteria['WHERE']  += $where;
      } else {
         //FIXME: dunno how to get rules without criteria/pattern
         if (!is_array($criteria['SELECT'])) {
            $criteria['SELECT'] = [$criteria['SELECT']];
         }
         $criteria['SELECT'][] = new QueryExpression("COUNT(IF(crit.criteria = 'itemtype', IF(crit.pattern IN ('".implode("', '", array_keys($tabs))."'), 1, NULL), NULL)) AS is_itemtype");
         $where = [];
         $criteria['HAVING'] = ['is_itemtype' => 0];
      }
      return $criteria;
   }

   public function getMainTabLabel() {
      return __('All');
   }
}
