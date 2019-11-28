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

namespace Glpi\Inventory\Asset;

use \Glpi\Inventory\Conf;

class Printer extends InventoryAsset
{

   public function prepare() :array {
      $rulecollection = new \RuleDictionnaryPrinterCollection();

      foreach ($this->data as $k => &$val) {
         $val->is_dynamic = 1;
         if (strstr($val->port, "USB")) {
            $val->have_usb = 1;
         } else {
            $val->have_usb = 0;
         }
         unset($val->port);

         // Hack for USB Printer serial
         if (property_exists($val, 'serial')
               && preg_match('/\/$/', $val->serial)) {
            $val->serial = preg_replace('/\/$/', '', $val->serial);
         }

         $res_rule = $rulecollection->processAllRules(['name' => $val->name]);
         if ((!isset($res_rule['_ignore_ocs_import']) || $res_rule['_ignore_ocs_import'] != "1")
            && (!isset($res_rule['_ignore_import']) || $res_rule['_ignore_import'] != "1")
         ) {
            if (isset($res_rule['name'])) {
               $val->name = $res_rule['name'];
            }
            if (isset($res_rule['manufacturer'])) {
               $val->manufacturers_id = $res_rule['manufacturer'];
            }
         }
      }

      return $this->data;
   }

   public function handle() {
      global $DB;

      // * Printers
      $rule = new \RuleImportComputerCollection();
      $printer = new \Printer();
      $printers = [];
      $entities_id = $this->entities_id;
      foreach ($this->data as $key => $val) {
         $input = [
            'itemtype' => "Printer",
            'name'     => $val->name,
            'serial'   => $val->serial ?? ''
         ];
         $data = $rule->processAllRules($input, [], ['class' => $this, 'return' => true]);
         if (isset($data['found_inventories'])) {
            if ($data['found_inventories'][0] == 0) {
               // add printer
               $val->entities_id = $entities_id;
               /*$val->otherserial = PluginFusioninventoryToolbox::setInventoryNumber(
                  'Printer', '', $entities_id);*/
               $printers[] = $printer->add(\Toolbox::addslashes_deep((array)$val));
            } else {
               $printers[] = $data['found_inventories'][0];
            }
            /** TODO
            if (isset($_SESSION['plugin_fusioninventory_rules_id'])) {
               $pfRulematchedlog = new PluginFusioninventoryRulematchedlog();
               $inputrulelog = [];
               $inputrulelog['date'] = date('Y-m-d H:i:s');
               $inputrulelog['rules_id'] = $_SESSION['plugin_fusioninventory_rules_id'];
               if (isset($_SESSION['plugin_fusioninventory_agents_id'])) {
                  $inputrulelog['plugin_fusioninventory_agents_id'] =
                                 $_SESSION['plugin_fusioninventory_agents_id'];
               }
               $inputrulelog['items_id'] = end($printers);
               $inputrulelog['itemtype'] = "Printer";
               $inputrulelog['method'] = 'inventory';
               $pfRulematchedlog->add($inputrulelog, [], false);
               $pfRulematchedlog->cleanOlddata(end($printers), "Printer");
               unset($_SESSION['plugin_fusioninventory_rules_id']);
            }*/

         }
      }
      $db_printers = [];
      $iterator = $DB->request([
         'SELECT'    => [
            'glpi_printers.id',
            'glpi_computers_items.id AS link_id'
         ],
         'FROM'      => 'glpi_computers_items',
         'LEFT JOIN' => [
            'glpi_printers' => [
               'FKEY' => [
                  'glpi_printers'         => 'id',
                  'glpi_computers_items'  => 'items_id'
               ]
            ]
         ],
         'WHERE'     => [
            'itemtype'                          => 'Printer',
            'computers_id'                      => $this->item->fields['id'],
            'entities_id'                       => $entities_id,
            'glpi_computers_items.is_dynamic'   => 1,
            'glpi_printers.is_global'           => 0
         ]
      ]);

      while ($data = $iterator->next()) {
         $idtmp = $data['link_id'];
         unset($data['link_id']);
         $db_printers[$idtmp] = $data['id'];
      }
      if (count($db_printers) == 0) {
         foreach ($printers as $printers_id) {
            $input['entities_id']    = $entities_id;
            $input['computers_id']   = $this->item->fields['id'];
            $input['itemtype']       = 'Printer';
            $input['items_id']       = $printers_id;
            $input['is_dynamic']     = 1;
            //$input['_no_history']    = $no_history;
            $computer_Item->add($input, []/*, !$no_history*/);
         }
      } else {
         // Check all fields from source:
         foreach ($printers as $key => $printers_id) {
            foreach ($db_printers as $keydb => $prints_id) {
               if ($printers_id == $prints_id) {
                  unset($printers[$key]);
                  unset($db_printers[$keydb]);
                  break;
               }
            }
         }

         // Delete printers links in DB
         foreach ($db_printers as $idtmp => $data) {
            $computer_Item->delete(['id'=>$idtmp], 1);
         }

         foreach ($printers as $printers_id) {
            $input['entities_id']    = $entities_id;
            $input['computers_id']   = $this->item->fields['id'];
            $input['itemtype']       = 'Printer';
            $input['items_id']       = $printers_id;
            $input['is_dynamic']     = 1;
            //$input['_no_history']    = $no_history;
            $computer_Item->add($input, []/*, !$no_history*/);
         }
      }

   }

   public function checkConf(Conf $conf) {
      return true;
   }
}
