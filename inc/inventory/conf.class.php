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
      'manage_osname'                  => 0
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

   public function importfile($files) {
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
         Session::addMessageAfterRedirect(
            __('File has been successfully imported!'),
            INFO
         );
      } catch (\Exception $e) {
         throw $e;
      }
   }
}
