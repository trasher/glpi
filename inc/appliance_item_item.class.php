<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

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
**/
class Appliance_Item_Item extends CommonDBRelation {

   static public $itemtype_1 = 'Appliance_Item';
   static public $items_id_1 = 'appliances_items_id';
   static public $take_entity_1 = false;

   static public $itemtype_2 = 'itemtype';
   static public $items_id_2 = 'items_id';
   static public $take_entity_2 = true;

   static function getTypeName($nb = 0) {
      return _n('Relation', 'Relations', $nb);
   }


   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if (!Appliance_Item::canView()) {
         return '';
      }

      $nb = 0;
      if ($item->getType() == Appliance_Item::class) {
         if ($_SESSION['glpishow_count_on_tabs']) {
            if (!$item->isNewItem()) {
               $nb = self::countForMainItem($item);
            }
         }
         return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $nb);

      } else if (in_array($item->getType(), Appliance_Item::getTypes(true))) {
         if ($_SESSION['glpishow_count_on_tabs']) {
            $nb = self::countForItem($item);
         }
         return self::createTabEntry(Appliance_Item::getTypeName(Session::getPluralNumber()), $nb);
      }
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      switch ($item->getType()) {
         case Appliance_Item::class:
            self::showItems($item, $withtemplate);
            break;
         default :
            if (in_array($item->getType(), Appliance_Item::getTypes())) {
               self::showForItem($item, $withtemplate);
            }
      }
      return true;
   }

   /**
    * Print items
    *
    * @return void
   **/
   static function showItems(Appliance_Item $appliance) {
   }

   /**
    * Print an HTML array of appliances associated to an object
    *
    * @since 9.5.2
    *
    * @param CommonDBTM $item         CommonDBTM object wanted
    * @param boolean    $withtemplate not used (to be deleted)
    *
    * @return void
   **/
   static function showForItem(CommonDBTM $item, $withtemplate = 0) {
   }


   function prepareInputForAdd($input) {
      return $this->prepareInput($input);
   }

   function prepareInputForUpdate($input) {
      return $this->prepareInput($input);
   }

   /**
    * Prepares input (for update and add)
    *
    * @param array $input Input data
    *
    * @return array
    */
   private function prepareInput($input) {
      $error_detected = [];

      //check for requirements
      if (($this->isNewItem() && (!isset($input['itemtype']) || empty($input['itemtype'])))
          || (isset($input['itemtype']) && empty($input['itemtype']))) {
         $error_detected[] = __('An item type is required');
      }
      if (($this->isNewItem() && (!isset($input['items_id']) || empty($input['items_id'])))
          || (isset($input['items_id']) && empty($input['items_id']))) {
         $error_detected[] = __('An item is required');
      }
      if (($this->isNewItem() && (!isset($input[self::$items_id_1]) || empty($input[self::$items_id_1])))
          || (isset($input[self::$items_id_1]) && empty($input[self::$items_id_1]))) {
         $error_detected[] = __('An appliance item is required');
      }

      if (count($error_detected)) {
         foreach ($error_detected as $error) {
            Session::addMessageAfterRedirect(
               $error,
               true,
               ERROR
            );
         }
         return false;
      }

      return $input;
   }

   public static function countForMainItem(CommonDBTM $item, $extra_types_where = []) {
      $types = Appliance_Item::getTypes();
      $clause = [];
      if (count($types)) {
         $clause = ['itemtype' => $types];
      } else {
         $clause = [new \QueryExpression('true = false')];
      }
      $extra_types_where = array_merge(
         $extra_types_where,
         $clause
      );
      return parent::countForMainItem($item, $extra_types_where);
   }
}
