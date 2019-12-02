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

namespace Glpi\Inventory;

use Session;

/**
 * Inventory configuration
 */
class Conf extends \CommonGLPI
{
   private $currents = [];
   public static $defaults = [
      'import_software'                => 1,
      'import_volume'                  => 1,
      'import_antivirus'               => 1,
      'import_registry'                => 1,
      'import_process'                 => 1,
      'import_vm'                      => 1,
      'import_monitor_on_partial_sn'   => 0,
      'component_processor'            => 1,
      'component_memory'               => 1,
      'component_harddrive'            => 1,
      'component_networkcard'          => 1,
      'component_graphiccard'          => 1,
      'component_soundcard'            => 1,
      'component_drive'                => 1,
      'component_networkdrive'         => 1,
      'component_networkcardvirtual'   => 1,
      'component_control'              => 1,
      'component_battery'              => 1,
      'states_id_default'              => 0,
      'location'                       => 0,
      'group'                          => 0,
      'manage_osname'                  => 1
   ];


   /**
    * Display form for import the XML
    *
    * @global array $CFG_GLPI
    * @return boolean
    */
   function showUploadForm() {
      echo "<form action='' method='post' enctype='multipart/form-data'>";
      echo "<table class='tab_cadre'>";
      echo "<tr>";
      echo "<th>";
      echo __('Import inventory file');
      echo "</th>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo sprintf(
         __("You can use this menu to upload any inventory file. The file must have a known extension (%1\$s).\n"),
         implode(', ', $this->knownInventoryExtensions())
      );
      echo '<br/>'.__('It is also possible to upload <b>ZIP</b> archive directly with a collection of inventory files.');
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td align='center'>";
      echo "<input type='file' name='importfile' value=''/>";
      echo "&nbsp;<input type='submit' value='".__('Import')."' class='submit'/>";
      echo "</td>";
      echo "</tr>";

      echo "</table>";

      \Html::closeForm();
      return true;
   }

   public function knownInventoryExtensions() :array {
      return [
         'json',
         'xml',
         'ocs'
      ];
   }

   public function importFile($files) {
      //error_log($files['importfile']['name']);
      ini_set("memory_limit", "-1");
      ini_set("max_execution_time", "0");

      $path = $files['importfile']['tmp_name'];
      $name = $files['importfile']['name'];

      $inventory_request = new Request();

      if (preg_match('/\.zip/i', $name)) {
         $zip = zip_open($path);

         if (!$zip) {
            Session::addMessageAfterRedirect(
               __("Can't read zip file!"),
               ERROR
            );
         } else {
            while ($zip_entry = zip_read($zip)) {
               //FIXME: not tested
               if (zip_entry_open($zip, $zip_entry, "r")) {
                  $contents = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
                  //$inventory_request->setCompression(finfo_file($finfo, $zip_entry));
                  $this->importContentFile($inventory_request, $contents);
                  zip_entry_close($zip_entry);
               }
            }
            zip_close($zip);
         }
      } else if (preg_match('/\.('.implode('|', $this->knownInventoryExtensions()).')/i', $name)) {
         $contents = file_get_contents($path);
         //$inventory_request->setCompression(finfo_file($finfo, $path));
         $this->importContentFile($inventory_request, $path, $contents);
      } else {
         Session::addMessageAfterRedirect(
            __('No file to import!'),
            ERROR
         );
      }
   }

   protected function importContentFile($inventory_request, $path, $contents) {
      //\Toolbox::logDebug($contents);
      try {
         $finfo = finfo_open(FILEINFO_MIME_TYPE);
         $mime = finfo_file($finfo, $path);
         switch ($mime) {
            case 'text/xml':
               $mime = 'application/xml';
               break;
         }
         $inventory_request->setCompression($mime);
         $inventory_request->handleRequest($contents);
         if ($inventory_request->inError()) {
            Session::addMessageAfterRedirect(
               __('File has not been imported:') . " " . $inventory_request->getResponse(),
               true,
               ERROR
            );
         } else {
            Session::addMessageAfterRedirect(
               __('File has been successfully imported!'),
               true,
               INFO
            );
         }
      } catch (\Exception $e) {
         throw $e;
      }
   }

   function defineTabs($options = []) {
      $ong = [];
      $this->addStandardTab(__CLASS__, $ong, $options);

      return $ong;
   }

   function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0) {
      switch ($item->getType()) {
         case __CLASS__ :
            $tabs = [
               1 => __('Configuration'),
               2 => __('Import from file')
            ];
            return $tabs;
      }
      return '';
   }

   static function displayTabContentForItem(\CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item->getType() == __CLASS__) {
         switch ($tabnum) {
            case 1 :
               $item->showConfigForm();
               break;

            case 2 :
               $item->showUploadForm();
               break;
         }
      }
      return true;
   }

   /**
    * Print the config form for display
    *
    * @return void
   **/
   private function showConfigForm() {
      global $CFG_GLPI;

      $config = \Config::getConfigurationValues('Inventory');
      $canedit = \Config::canUpdate();

      if ($canedit) {
         echo "<form name='form' action='".$CFG_GLPI['root_doc']."/front/inventory.conf.php' method='post'>";
      }

      echo "<div class='center spaced' id='tabsbody'>";
      echo "<table class='tab_cadre_fixe'>";

      echo "<tr>";
      echo "<th colspan='4'>";
      echo __('Import options');
      echo "</th>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo "<label for='import_volume'>";
      echo \Item_Disk::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td width='360'>";
      \Html::showCheckbox([
         'name'      => 'import_volume',
         'id'        => 'import_volume',
         'checked'   => $config['import_volume']
      ]);
      echo "</td>";

      echo "<td>";
      echo "<label for='import_software'>";
      echo \Software::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'import_software',
         'id'        => 'import_software',
         'checked'   => $config['import_software']
      ]);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo "<label for='import_vm'>";
      echo \ComputerVirtualMachine::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'import_vm',
         'id'        => 'import_vm',
         'checked'   => $config['import_vm']
      ]);
      echo "</td>";

      echo "<td>";
      echo "<label for='import_antivirus'>";
      echo \ComputerAntivirus::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'import_antivirus',
         'id'        => 'import_antivirus',
         'checked'   => $config['import_antivirus']
      ]);
      echo "</td>";
      echo "</tr>";

      /*echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo "<label for='location'>";
      echo _n('Location', 'Locations', 2);
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Dropdown::showFromArray("location",
                              ["0"=> Dropdown::EMPTY_VALUE,
                                    "1"=>__('FusionInventory tag', 'fusioninventory')],
                                    ['value'=>$config['location']]);
      echo "</td>";*/

      /*echo "<td>";
      echo "<label for='group'>";
      echo _n('Group', 'Groups', 2);
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Dropdown::showFromArray("group",
                              ["0"=> Dropdown::EMPTY_VALUE,
                                    "1"=>__('FusionInventory tag', 'fusioninventory')],
                                    ['value'=>$config['group']]);
      echo "</td>";
      echo "</tr>";*/

      echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo "<label for='states_id_default'>";
      echo __('Default status');
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Dropdown::show(
         'State', [
            'name'   => 'states_id_default',
            'id'     => 'states_id_default',
            'value'  => $config['states_id_default']
         ]);
      echo "</td>";

      echo "<td>";
      echo "<label for='component_soundcard'>";
      echo \DeviceSoundcard::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'component_soundcard',
         'id'        => 'component_soundcard',
         'checked'   => $config['component_soundcard']
      ]);

      echo "</td>";
      echo "</tr>";

      /*echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo "<label for='otherserial'>";
      echo __('Inventory number');
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Dropdown::showFromArray("otherserial",
                              ["0"=> \Dropdown::EMPTY_VALUE,
                                    "1"=>__('FusionInventory tag', 'fusioninventory')],
                              ['value'=>$config['otherserial']]);
      echo "</td>";

      echo "<td>";
      echo __('Create computer based on virtual machine information ( only when the virtual machine has no inventory agent ! )', 'fusioninventory');
      echo "</td>";

      echo "<td>";
      \Dropdown::showYesNo("create_vm", $config['create_vm']);
      echo "</td>";
      echo "</tr>";*/

      echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo "<label for='manage_osname'>";
      echo __('Manage operating system name');
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'manage_osname',
         'id'        => 'manage_osname',
         'checked'   => $config['manage_osname']
      ]);
      echo "</td>";
      echo "<td>";
      echo "<label for='import_monitor_on_partial_sn'>";
      echo __('Import monitor on serial partial match');
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'import_monitor_on_partial_sn',
         'id'        => 'import_monitor_on_partial_sn',
         'checked'   => $config['import_monitor_on_partial_sn']
      ]);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<th colspan='4'>";
      echo \CommonDevice::getTypeName(\Session::getPluralNumber());
      echo "</th>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo "<label for='component_processor'>";
      echo \DeviceProcessor::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'component_processor',
         'id'        => 'component_processor',
         'checked'   => $config['component_processor']
      ]);
      echo "</td>";

      echo "<td>";
      echo "<label for='component_harddrive'>";
      echo \DeviceHarddrive::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'component_harddrive',
         'id'        => 'component_harddrive',
         'checked'   => $config['component_harddrive']
      ]);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo "<label for='component_memory'>";
      echo \DeviceMemory::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'component_memory',
         'id'        => 'component_memory',
         'checked'   => $config['component_memory']
      ]);
      echo "</td>";

      /*echo "<td>";
      echo "<label for='component_networkcard'>";
      echo \DeviceNetworkCard::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'component_networkcard',
         'id'        => 'component_networkcard',
         'checked'   => $config['component_networkcard']
      ]);
      echo "</td>";*/
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo "<label for='component_graphiccard'>";
      echo \DeviceGraphicCard::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'component_graphiccard',
         'id'        => 'component_graphiccard',
         'checked'   => $config['component_graphiccard']
      ]);
      echo "</td>";

      /*echo "<td>";
      echo "<label for='component_networkcardvirtual'>";
      echo __('Virtual network card', 'fusioninventory');
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'component_networkcardvirtual',
         'id'        => 'component_networkcardvirtual',
         'checked'   => $config['component_networkcardvirtual']
      ]);
      echo "</td>";*/
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo "<label for='component_drive'>";
      echo \DeviceDrive::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'component_drive',
         'id'        => 'component_drive',
         'checked'   => $config['component_drive']
      ]);
      echo "</td>";

      /*echo "<td>";
      echo "<label for='component_networkdrive'>";
      echo __('Network drives', 'fusioninventory');
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'component_networkdrive',
         'id'        => 'component_networkdrive',
         'checked'   => $config['component_networkdrive']
      ]);
      echo "</td>";*/
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo "<label for='component_control'>";
      echo \DeviceControl::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'component_control',
         'id'        => 'component_control',
         'checked'   => $config['component_control']
      ]);
      echo "</td>";

      echo "</td>";
      echo "<td>";
      echo "<label for='component_battery'>";
      echo \DeviceBattery::getTypeName(\Session::getPluralNumber());
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Html::showCheckbox([
         'name'      => 'component_battery',
         'id'        => 'component_battery',
         'checked'   => $config['component_battery']
      ]);
      echo "</td>";
      echo "</tr>";

      /*echo "<tr class='tab_bg_1'>";
      echo "<td>";
      echo "<label for='component_removablemedia'>";
      echo _n('Removable medias', 'Removable medias', 2, "fusioninventory");
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Dropdown::showYesNo("component_removablemedia",
                          $config['component_removablemedia']);
      echo "</td>";

      echo "<td>";
      echo "<label for='component_simcard'>";
      echo _n('Simcard', 'Simcards', 2);
      echo "</label>";
      echo "</td>";
      echo "<td>";
      \Dropdown::showYesNo("component_simcard",
                          $config['component_simcard']);
      echo "</td>";

      echo "</tr>";*/

      if ($canedit) {
         echo "<tr class='tab_bg_2'>";
         echo "<td colspan='7' class='center'>";
         echo "<input type='submit' name='update' class='submit' value=\""._sx('button', 'Save')."\">";
         echo "</td></tr>";
      }

      echo "</table></div>";
      \Html::closeForm();
      return true;
   }

   public function saveConf($values) {
      if (!\Config::canUpdate()) {
         return false;
      }

      $defaults = self::$defaults;
      //TODO: what to do? :)
      $unknown = array_diff_key($values, $defaults);
      if (count($unknown)) {
         \Session::addMessageAfterRedirect(
            sprintf(
               __('Some properties are not known: %1$s'),
               implode(', ', array_keys($unknown))
            ),
            false,
            WARNING
         );
      }
      $to_process = [];
      foreach (array_keys($defaults) as $prop) {
         $to_process[$prop] = $values[$prop] ?? 0;
      }
      \Config::setConfigurationValues('inventory', $to_process);
   }

   public function __get($name) {
      if (!count($this->currents)) {
         $config = \Config::getConfigurationValues('Inventory');
         $this->currents = $config;
      }
      if (in_array($name, array_keys(self::$defaults))) {
         return $this->currents[$name];
      }
   }
}
