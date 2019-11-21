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

class Monitor extends InventoryAsset
{
   private $import_monitor_on_partial_sn = false;

   public function prepare() :array {
      $serials = [];
      $mapping = [
         'caption'      => 'name',
         'manufacturer' => 'manufacturers_id',
         'serial'       => 'serial',
         'description'  => 'comment'
      ];

      foreach ($this->data as $k => &$val) {
         foreach ($mapping as $origin => $dest) {
            if (property_exists($val, $origin)) {
               $val->$dest = $val->$origin;
            }
         }

         $val->is_dynamic = 1;
         if (!property_exists($val, 'name')) {
            $val->name = '';
         }

         if (property_exists($val, 'comment')) {
            if ($val->name == '') {
               $array_tmp['name'] = $array_tmp['comment'];
            }
            unset($val->comment);
         }

         if (!property_exists($val, 'serial')) {
            $val->serial = '';
         }

         if (!property_exists($val, 'manufacturers_id')) {
            $val->manufacturers_id = '';
         }

         if (!isset($serials[$val->serial])) {
            $this->linked_items['Monitor'][] = $val;
            $serials[$val->serial] = 1;
         }
      }

      return $this->data;
   }

   public function handle() {
      //TODO
   }

   public function checkConf(Conf $conf) {
      $this->import_monitor_on_partial_sn = $conf->import_monitor_on_partial_sn;
      return true;
   }
}
