<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2021 Teclib' and contributors.
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

use Database as GDatabase;
use DatabaseInstance;
use Glpi\Inventory\Conf;
use Toolbox;

class Database extends InventoryAsset
{
   public function prepare() :array {
      $mapping = [
         'type' => 'databasetypes_id',
         'manufacturer' => 'manufacturers_id',
         'port' => 'instance_port',
         'size' => 'instance_size',
         'is_onbackup' => 'instance_is_onbackup'
      ];

      foreach ($this->data as &$val) {
         foreach ($mapping as $origin => $dest) {
            if (property_exists($val, $origin)) {
               $val->$dest = $val->$origin;
            }
         }
      }

      return $this->data;
   }

   /**
    * Get existing entries from database
    *
    * @return array
    */
   protected function getExisting(): array {
      global $DB;

      $db_existing = [];

      $iterator = $DB->request([
         'SELECT' => [
            GDatabase::getTable() . '.id AS dbid',
            GDatabase::getTable() . '.name',
            DatabaseInstance::getTable() . '.id AS iid',
            DatabaseInstance::getTable() . '.name AS instance_name',
            'port',
            'databasetypes_id',
            'manufacturers_id'
         ],
         'FROM'   => DatabaseInstance::getTable(),
         'LEFT JOIN' => [
            GDatabase::getTable() => [
               'ON' => [
                  DatabaseInstance::getTable() => 'databases_id',
                  GDatabase::getTable() => 'id'

               ]
            ]
         ],
         'WHERE'  => [
            GDatabase::getTable() . '.is_dynamic'   => 1
         ]
      ]);

      while ($row = $iterator->next()) {
         $idtmp = $row['iid'];
         unset($row['iid']);
         $db_existing[$idtmp] = $row;
      }

      return $db_existing;
   }

   public function handle() {
      global $DB;

      $value = $this->data;
      $database = new GDatabase();
      $dbitem = new \Database_Item();

      $db_instances = $this->getExisting();

      foreach ($db_instances as $keydb => $arraydb) {
         //update existing databases
         $dbid = $arraydb['dbid'];
         unset($arraydb['dbid']);
         //TODO: update database, its instance, and link to item
         foreach ($value as $key => $val) {
            $sinput = [
               'name' => $val->name ?? '',
               'instance_name' => $val->instance_name ?? '',
               'port' => $val->port ?? '',
               'databasestypes_id' => $val->databasetypes_id ?? 0,
               'manufacturers_id' => $val->manufacturers_id ?? 0,
            ];
            if ($sinput != $arraydb) {
               $input = [
                  'id'           => $dbid,
                  'is_dynamic'   => 1
               ] + $sinput;

               $database->update(Toolbox::addslashes_deep($input), $this->withHistory());
               unset($value[$key]);
               unset($db_instances[$dbid]);
               break;
            }
         }
      }

      if ((!$this->main_asset || !$this->main_asset->isPartial()) && count($db_instances) != 0) {
         //remove no longer existing databases
         foreach ($db_instances as $idtmp => $data) {
            $database->delete(['id' => $idtmp], 1);
         }
      }

      if (count($value) != 0) {
         //add new databases
         foreach ($value as $val) {
            $input = (array)$val;
            $input['is_dynamic']  = 1;
            $database->add(Toolbox::addslashes_deep($input), [], $this->withHistory());

            if ($this->item) {
               //link with main item
               $dbitem->add(
                  [
                     'databases_id' => $database->fields['id'],
                     'itemtype' => $this->item->getType(),
                     'items_id' => $this->item->fields['id']
                  ],
                  $this->withHistory()
               );
            }
         }
      }
   }

   public function checkConf(Conf $conf): bool {
      return true;
   }
}
