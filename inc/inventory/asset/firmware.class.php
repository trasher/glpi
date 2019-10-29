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

class Firmware extends Device
{
   public function __construct(\CommonDBTM $item, array $data = null) {
      parent::__construct($item, $data, 'Item_DeviceFirmware');
   }

   public function prepare() :array {

      $itemdevicetype = 'Item_DeviceFirmware';
      $mapping = [
         'bdate'           => 'date',
         'bversion'        => 'version',
         'bmanufacturer'   => 'manufacturers_id',
         'biosserial'      => 'serial'
      ];

      $val = (object)$this->data;
      foreach ($mapping as $origin => $dest) {
         if (property_exists($val, $origin)) {
            $val->$dest = $val->$origin;
         }
      }

      $val->designation = sprintf(
         __('%1$s BIOS'),
         property_exists($val, 'bmanufacturer') ? $val->bmanufacturer : ''
      );

      if (property_exists($val, 'date')) {
         $matches = [];
         preg_match("/^(\d{2})\/(\d{2})\/(\d{4})$/", $val->date, $matches);
         if (count($matches) == 4) {
            $val->date = $matches[3]."-".$matches[1]."-".$matches[2];
         } else {
            unset($val->date);
         }
      }

      $this->data = [$val];
      return $this->data;
   }
}
