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


/**
 * @since 9.5
 **/
class Agent extends CommonDBTM {

   /**
    * @var boolean
    */
   public $dohistory = true;

   /**
    * @var string
    */
   static $rightname = 'computer';
   //static $rightname = 'inventory';

   static function getTypeName($nb = 0) {
      return _n('Agent', 'Agents', $nb);
   }

   /**
    * Get search function for the class
    *
    * @return array
    */
   function rawSearchOptions() {

      $tab = [
         [
            'id'            => 'common',
            'name'          => self::getTypeName(1)
         ], [
            'id'            => '1',
            'table'         => $this->getTable(),
            'field'         => 'name',
            'name'          => __('Name'),
            'datatype'      => 'itemlink',
         ], [
            'id'            => '2',
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => __('Entity'),
            'datatype'      => 'dropdown',
         ], [
            'id'            => '3',
            'table'         => $this->getTable(),
            'field'         => 'is_recursive',
            'name'          => __('Child entities'),
            'datatype'      => 'bool',
         ], [
            'id'            => '4',
            'table'         => $this->getTable(),
            'field'         => 'last_contact',
            'name'          => __('Last contact'),
            'datatype'      => 'datetime',
         ], [
            'id'            => '5',
            'table'         => $this->getTable(),
            'field'         => 'lock',
            'name'          => __('locked'),
            'datatype'      => 'bool',
         ], [
            'id'            => '6',
            'table'         => $this->getTable(),
            'field'         => 'device_id',
            'name'          => __('Device_id'),
            'datatype'      => 'text',
            'massiveaction' => false,
         ], [
            'id'            => '7',
            'table'         => 'glpi_computers',
            'field'         => 'name',
            'name'          => __('Computer link'),
            'datatype'      => 'itemlink',
            'itemlink_type' => 'Computer',
            'massiveaction' => false,
         ], [
            'id'            => '8',
            'table'         => $this->getTable(),
            'field'         => 'version',
            'name'          => __('Version'),
            'datatype'      => 'text',
            'massiveaction' => false,
         ], [
            'id'            => '9',
            'table'         => $this->getTable(),
            'field'         => 'token',
            'name'          => __('Token'),
            'datatype'      => 'text',
            'massiveaction' => false,
         ], [
            'id'            => '10',
            'table'         => $this->getTable(),
            'field'         => 'useragent',
            'name'          => __('Useragent'),
            'datatype'      => 'text',
            'massiveaction' => false,
         ], [
            'id'            => '11',
            'table'         => $this->getTable(),
            'field'         => 'tag',
            'name'          => __('Tag'),
            'datatype'      => 'text',
            'massiveaction' => false,
         ], [
            'id'            => '14',
            'table'         => $this->getTable(),
            'field'         => 'agent_port',
            'name'          => __('Port'),
            'datatype'      => 'integer',
         ]
      ];

      return $tab;
   }

   /**
    * Define tabs to display on form page
    *
    * @param array $options
    * @return array containing the tabs name
    */
   function defineTabs($options = []) {

      $ong = [];
      $this->addDefaultFormTab($ong);
      $this->addStandardTab('Log', $ong, $options);

      return $ong;
   }

   /**
    * Display form for agent configuration
    *
    * @param integer $id      ID of the agent
    * @param array   $options Options
    *
    * @return boolean
    */
   function showForm($id, $options = []) {
      global $CFG_GLPI;

      if (!empty($id)) {
         $this->getFromDB($id);
      } else {
         $this->getEmpty();
      }
      $this->initForm($id, $options);
      $this->showFormHeader($options);

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='name'>".__('Name')."</label></td>";
      echo "<td align='center'>";
      Html::autocompletionTextField($this, 'name', ['size' => 40]);
      echo "</td>";
      echo "<td>".__('Locked')."</td>";
      echo "<td align='center'>";
      Dropdown::showYesNo('lock', $this->fields["lock"]);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Port')."</td>";
      echo "<td align='center'>";
      echo "<input type='text' name='agent_port' value='".$this->fields['agent_port']."'/>";
      echo "</td>";
      echo "<td><label for='agenttypes_id'>".AgentType::getTypeName(1)."</label></td>";
      echo "<td align='center'>";

      $value = $this->isNewItem() ? 1 : $this->fields['agenttypes_id'];
      AgentType::dropdown(['value' => $value]);

      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Item link')."</td>";
      echo "<td align='center'>";
      if (!empty($this->fields["items_id"])) {
         $asset = new $this->fields['itemtype'];
         $asset->getFromDB($this->fields['items_id']);
         echo $asset->getLink(1);
         echo Html::hidden(
            'items_id',
            [
               'value' => $this->fields["items_id"]
            ]
         );
      } else {
         //TODO: not only computers
         echo "<input type='hidden' name='itemtype' value='Computer'/>";
         Computer_Item::dropdownConnect("Computer", "Computer", 'itemss_id',
            $this->fields['entities_id']);
      }
      echo "</td>";
      echo "</tr>";

      if (!$this->isNewItem()) {
         echo "<tr class='tab_bg_1'>";
         echo "<td>".__('Version')."</td>";
         echo "<td align='center'>";
         $versions = importArrayFromDB($this->fields["version"]);
         foreach ($versions as $module => $version) {
            echo "<strong>".$module. "</strong>: ".$version."<br/>";
         }
         echo "</td>";
         echo "<td>".__('Token')."</td>";
         echo "<td align='center'>";
         echo $this->fields["token"];
         echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
         echo "<td>".__('Useragent')."</td>";
         echo "<td align='center'>";
         echo $this->fields["useragent"];
         echo "</td>";
         echo "<td>".__('Last contact')."</td>";
         echo "<td align='center'>";
         echo Html::convDateTime($this->fields["last_contact"]);
         echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
         echo "<td>".__('Tag')."</td>";
         echo "<td align='center'>";
         echo $this->fields["tag"];
         echo "</td>";
         echo "<td colspan='2'>";
         echo "</td>";
         echo "</tr>";
      }

      $this->showFormButtons($options);

      return true;
   }
}
