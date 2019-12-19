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
            'field'         => 'locked',
            'name'          => __('Locked'),
            'datatype'      => 'bool',
         ], [
            'id'            => '6',
            'table'         => $this->getTable(),
            'field'         => 'deviceid',
            'name'          => __('Device id'),
            'datatype'      => 'text',
            'massiveaction' => false,
         ], /*[
            'id'            => '7',
            'table'         => 'glpi_computers',
            'field'         => 'name',
            'name'          => __('Computer link'),
            'datatype'      => 'itemlink',
            'itemlink_type' => 'Computer',
            'massiveaction' => false,
         ],*/ [
            'id'            => '8',
            'table'         => $this->getTable(),
            'field'         => 'version',
            'name'          => __('Version'),
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
            'field'         => 'port',
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
      Dropdown::showYesNo('locked', $this->fields["locked"]);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='deviceid'>".__('Device id')."</label></td>";
      echo "<td align='center'>";
      echo "<input type='text' name='deviceid' id='deviceid' value='".$this->fields['deviceid']."' required='required'/>";
      echo "</td>";
      echo "<td>".__('Port')."</td>";
      echo "<td align='center'>";
      echo "<input type='text' name='port' value='".$this->fields['port']."'/>";
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='agenttypes_id'>".AgentType::getTypeName(1)."</label></td>";
      echo "<td align='center'>";

      $value = $this->isNewItem() ? 1 : $this->fields['agenttypes_id'];
      AgentType::dropdown(['value' => $value]);

      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".__('Item type')."</td>";
      echo "<td align='center'>";
      Dropdown::showFromArray('itemtype', $CFG_GLPI['inventory_types'], ['value' => $this->fields['itemtype']]);
      echo "</td>";
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
         Computer_Item::dropdownConnect("Computer", "Computer", 'items_id',
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
         echo "<td>".__('Tag')."</td>";
         echo "<td align='center'>";
         echo $this->fields["tag"];
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
         echo "<td colspan='2'>";
         echo "</td>";
         echo "</tr>";
      }

      $this->showFormButtons($options);

      return true;
   }

   /**
    * Handle agent
    *
    * @param array $metadata Agents metadata from Inventory
    *
    * @return integer
    */
   public function handleAgent($metadata) {
      $deviceid = $metadata['deviceid'];
      $aid = false;
      if ($this->getFromDBByCrit(['deviceid' => $deviceid])) {
         $aid = $this->fields['id'];
      }

      $atype = new AgentType();
      $atype->getFromDBByCrit(['name' => 'Core']);

      $input = [
         'deviceid'     => $deviceid,
         'name'         => $deviceid,
         'last_contact' => date('Y-m-d H:i:s'),
         'useragent'    => $_SERVER['HTTP_USER_AGENT'] ?? null,
         'agenttypes_id'=> $atype->fields['id'],
         'itemtype'     => $metadata['itemtype'] ?? 'Computer'
      ];

      if (isset($metadata['provider']['version'])) {
         $input['version'] = $metadata['provider']['version'];
      }

      if (isset($metadata['tag'])) {
         $input['tag'] = $metadata['tag'];
      }

      $input = \Toolbox::addslashes_deep($input);
      if ($aid) {
         $input['id'] = $aid;
         $this->update($input);
      } else {
         $aid = $this->add($input);
      }

      return $aid;
   }

   /**
    * Preapre input for add and update
    *
    * @param array $input Input
    *
    * @return array|false
    */
   public function prepareInputs(array $input) {
      if ($this->isNewItem() && (!isset($input['deviceid']) || empty($input['deviceid']))) {
         Session::addMessageAfterRedirect(_('"deviceid" is mandatory!'), false, ERROR);
         return false;
      }
      return $input;
   }

   public function prepareInputForAdd($input) {
      return $this->prepareInputs($input);
   }

   public function prepareInputForUpdate($input) {
      return $this->prepareInputs($input);
   }

   /**
    * Display agent information for a computer
    *
    * @param Computer the computer
    * @param integer $colspan the number of columns of the form (2 by default)
    */
   public function showInventoryInfos(CommonDBTM $item, $colspan = 2) {
      $res = $this->getFromDBByCrit(['itemtype' => $item->getType(), 'items_id' => $item->fields['id']]);

      if (!$res) {
         return;
      }

      echo '<tr class="tab_bg_1">';
      echo '<td>'.__('Agent').'</td>';
      echo '<td>'.$this->getLink(1).'</td>';

      if ($colspan == 2) {
         echo '</tr>';
         echo '<tr class="tab_bg_1">';
      }

      echo '<td>'.__('Useragent').'</td>';
      echo '<td>'.$this->fields['useragent'].'</td>';
      echo '</tr>';

      if (isset($this->fields['id']) && !is_null($item) && isset($item->fields['id'])) {
         $agent_id = $this->fields['id'];
         $fi_path = $CFG_GLPI['root_doc']."/plugins/fusioninventory";

         echo "<tr class='tab_bg_1'>";
         echo "<td>";
         echo __('Status')."&nbsp;:";
         echo "</td>";
         echo "<td>";

         $load_anim   = '<i class="fas fa-sync fa-spin fa-fw"></i>';

         echo Html::scriptBlock("$(function() {
            var waiting = false;

            var refresh_status = function(display_refresh) {
               var display_refresh = (typeof display_refresh !== 'undefined') ? display_refresh : true;
               $('#agent_status').html('$load_anim');
               $('#refresh_status').hide();
               $('#force_inventory_button').hide();

               $.get('$fi_path/ajax/remote_status.php', {
                  id: $agent_id,
                  action: 'get_status'
               }, function(answer) {
                  if (typeof answer.waiting != 'undefined'
                     && answer.waiting == true) {
                     $('#force_inventory_button').show();
                     waiting = true;
                  }

                  $('#agent_status').html(answer.message);
                  if (display_refresh) {
                     $('#refresh_status').show();
                  }
               });
            };

            var force_inventory = function() {
               $('#agent_status').html('$load_anim');
               $('#refresh_status').hide();
               waiting = false;
               $('#force_inventory_button').hide();
               $.get('$fi_path/ajax/remote_status.php', {
                  id: $agent_id,
                  action: 'start_agent'
               }, function(answer) {
                  refresh_status(false);
                  displayAjaxMessageAfterRedirect();

                  // add a loop for checking status (set a max iterations to avoid infinite looping)
                  var loop_index = 0;
                  var myloop = setInterval(function() {
                     if (loop_index > 30 || waiting) {
                        clearInterval(myloop);
                        $('#refresh_status').show();
                        return;
                     }
                     refresh_status(false);
                     loop_index++;
                  }, 2000);
               });
            };

            $(document)
               .on('click', '#refresh_status', function() {
                  refresh_status();
               })
               .on('click', '#force_inventory_button', function() {
                  force_inventory();
               });
         });");
         echo "<span id='agent_status'>".
            __("not yet requested, refresh?", 'fusioninventory').
            "</span>";
         echo "<span id='refresh_status'><i class='fas fa-sync'></i></span>";
         echo "</td>";

         echo "<td colspan='2'>";
         echo "<span id='force_inventory_button'>".
            __('Force inventory', 'fusioninventory').
            "</span>";
         echo "</td>";
         echo "</tr>";
      }

      echo '<tr class="tab_bg_1">';
      echo '<td>'.__('Inventory tag').'</td>';
      echo '<td>'.$this->fields['tag'].'</td>';
      if ($colspan == 2) {
         echo '</tr>';
         echo '<tr class="tab_bg_1">';
      }

      echo '<td>';
      echo __('Last contact');
      echo '</td>';
      echo '<td>';
      echo Html::convDateTime($this->fields['last_contact']);
      echo '</td>';
      echo '</tr>';
   }
}
