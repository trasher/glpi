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
         if (property_exists($val, 'port') && strstr($val->port, "USB")) {
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

      $lclass = null;
      if (class_exists($this->item->getType() . '_Item')) {
         $lclass = $this->item->getType() . '_Item';
      } else if (class_exists('Item_' . $this->item->getType())) {
         $lclass = 'Item_' . $this->item->getType();
      } else {
         \RuntimeException('Unable to find linked item object name for ' . $this->item->getType());
      }
      $link_item = new $lclass;

      foreach ($this->data as $key => $val) {
         $input = [
            'itemtype'     => "Printer",
            'name'         => $val->name,
            'serial'       => $val->serial ?? '',
            'is_dynamic'   => 1
         ];
         $data = $rule->processAllRules($input, [], ['class' => $this, 'return' => true]);
         if (isset($data['found_inventories'])) {
            $items_id = null;
            $itemtype = 'Printer';
            if ($data['found_inventories'][0] == 0) {
               // add printer
               $val->entities_id = $entities_id;
               $val->is_dynamic = 1;
               $items_id = $printer->add(\Toolbox::addslashes_deep((array)$val));
            } else {
               $items_id = $data['found_inventories'][0];
            }

            $printers[] = $items_id;
            $rulesmatched = new \RuleMatchedLog();
            $inputrulelog = [
               'date'      => date('Y-m-d H:i:s'),
               'rules_id'  => $data['rules_id'],
               'items_id'  => $items_id,
               'itemtype'  => $itemtype,
               'agents_id' => $this->agent->fields['id'],
               'method'    => 'inventory'
            ];
            $rulesmatched->add($inputrulelog, [], false);
            $rulesmatched->cleanOlddata(end($printers), 'Printer');
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
      if (count($db_printers)) {
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
            $link_item->delete(['id'=>$idtmp], 1);
         }
      }

      foreach ($printers as $printers_id) {
         $input = [
            'entities_id'  => $entities_id,
            'computers_id' => $this->item->fields['id'],
            'itemtype'     => 'Printer',
            'items_id'     => $printers_id,
            'is_dynamic'   => 1
         ];
         $link_item->add($input, [], $this->withHistory());
      }
   }

   public function checkConf(Conf $conf) {
      return true;
   }
}
