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

class OperatingSystem extends InventoryAsset
{
   protected $extra_data = ['hardware' => null];
   private $manage_osname = false;

   public function prepare() :array {
      if ($this->item->getType() != 'Computer') {
         throw new \RuntimeException('Peripherals are handled for computers only.');
      }
      $mapping = [
         'name'           => 'operatingsystems_id',
         'version'        => 'operatingsystemversions_id',
         'service_pack'   => 'operatingsystemservicepacks_id',
         'arch'           => 'operatingsystemarchitectures_id',
         'kernel_name'    => 'operatingsystemkernels_id',
         'kernel_version' => 'operatingsystemkernelversions_id'
      ];

      $val = (object)$this->data;
      foreach ($mapping as $origin => $dest) {
         if (property_exists($val, $origin)) {
            $val->$dest = $val->$origin;
         }
      }

      //FIXME: original code did unset original licenseid key, dunno if it is stull mandatory
      if (isset($this->extra_data['winprodid'])) {
         $val->licenseid = $this->extra_data['winprodid'];
      } else if (isset($this->extra_data[0]['winprodid'])) {
         $val->licenseid = $this->extra_data[0]['winprodid'];
      }

      //FIXME: original code did unset original licenseid number, dunno if it is stull mandatory
      if (isset($this->extra_data['winprodid'])) {
         $val->license_number = $this->extra_data['winprodkey'];
      } else if (isset($this->extra_data[0]['winprodkey'])) {
         $val->license_number = $this->extra_data[0]['winprodkey'];
      }

      $val->operatingsystemeditions_id = '';
      if (property_exists($val, 'full_name') && $this->manage_osname == true) {
         $matches = [];
         preg_match("/.+ Windows (XP |\d\.\d |\d{1,4} |Vista(™)? )(.*)/", $val->full_name, $matches);
         if (count($matches) == 4) {
            $val->operatingsystemeditions_id = $matches[3];
            if (!property_exists($val, 'operatingsystemversions_id') || $val->operatingsystemversions_id == '') {
               $matches[1] = trim($matches[1]);
               if ($matches[2] != '') {
                  $matches[1] = trim($matches[1], $matches[2]);
               }
               $val->operatingsystemversions_id = $matches[1];
            }
         } else if (count($matches) == 2) {
            $val->operatingsystemeditions_id = $matches[1];
         } else {
            preg_match("/^(.*) GNU\/Linux (\d{1,2}|\d{1,2}\.\d{1,2}) \((.*)\)$/", $val->full_name, $matches);
            if (count($matches) == 4) {
               if (empty($val->operatingsystems_id)) {
                  $val->operatingsystems_id = $matches[1];
               }
               if (empty($val->operatingsystemkernelversions_id)) {
                  $val->operatingsystemkernelversions_id = $val->operatingsystemversions_id;
                  $val->operatingsystemversions_id = $matches[2] . " (".$matches[3].")";
               } else if (empty($val->operatingsystemversions_id)) {
                  $val->operatingsystemversions_id = $matches[2] . " (".$matches[3].")";
               }
               if (empty($val->operatingsystemkernels_id)) {
                  $val->operatingsystemkernels_id = 'linux';
               }
            } else {
               preg_match("/Linux (.*) (\d{1,2}|\d{1,2}\.\d{1,2}) \((.*)\)$/", $val->full_name, $matches);
               if (count($matches) == 4) {
                  if (empty($val->operatingsystemversions_id)) {
                     $val->operatingsystemversions_id = $matches[2];
                  }
                  if (empty($val->operatingsystemarchitectures_id)) {
                     $val->operatingsystemarchitectures_id = $matches[3];
                  }
                  if (empty($val->operatingsystemkernels_id)) {
                     $val->operatingsystemkernels_id = 'linux';
                  }
                  $val->operatingsystemeditions_id = trim($matches[1]);
               } else {
                  preg_match("/\w[\s\S]{0,4} (?:Windows[\s\S]{0,4} |)(.*) (\d{4} R2|\d{4})(?:, | |)(.*|)$/", $val->full_name, $matches);
                  if (count($matches) == 4) {
                     $val->operatingsystemversions_id = $matches[2];
                     $val->operatingsystemeditions_id = trim($matches[1] . " " . $matches[3]);
                  } else if ($val->full_name == 'Microsoft Windows Embedded Standard') {
                     $val->operatingsystemeditions_id = 'Embedded Standard';
                  } else if (empty($val->operatingsystems_id)) {
                     $val->operatingsystems_id = $val->full_name;
                  }
               }
            }
         }
      } else if (property_exists($val, 'full_name')) {
         $val->operatingsystems_id = $val->full_name;
      }

      if (property_exists($val, 'operatingsystemarchitectures_id')
         && $val->operatingsystemarchitectures_id != ''
      ) {
         $rulecollection = new \RuleDictionnaryOperatingSystemArchitectureCollection();
         $res_rule = $rulecollection->processAllRules(['name' => $val->operatingsystemarchitectures_id]);
         if (isset($res_rule['name'])) {
            $val->operatingsystemarchitectures_id = $res_rule['name'];
         }
         if ($val->operatingsystemarchitectures_id == '0') {
            $val->operatingsystemarchitectures_id = '';
         }
      }
      if (property_exists($val, 'operatingsystemservicepacks_id') && $val->operatingsystemservicepacks_id == '0') {
         $val->operatingsystemservicepacks_id = '';
      }

      $this->data = [$val];
      return $this->data;
   }

   public function handle() {
      $ios = new \Item_OperatingSystem();

      $val = $this->data[0];
      $ios->getFromDBByCrit([
         'itemtype'  => $this->item->getType(),
         'items_id'  => $this->item->fields['id']
      ]);

      $input_os = [
         'itemtype'                          => $this->item->getType(),
         'items_id'                          => $this->item->fields['id'],
         'operatingsystemarchitectures_id'   => $val->operatingsystemarchitectures_id,
         'operatingsystemkernelversions_id'  => $val->operatingsystemkernelversions_id,
         'operatingsystems_id'               => $val->operatingsystems_id,
         'operatingsystemversions_id'        => $val->operatingsystemversions_id ?? 0,
         'operatingsystemservicepacks_id'    => $val->operatingsystemservicepacks_id ?? 0,
         'operatingsystemeditions_id'        => $val->operatingsystemeditions_id,
         'licenseid'                         => $val->licenseid ?? '',
         'license_number'                    => $val->license_number ?? '',
         'is_dynamic'                        => 1,
         'entities_id'                       => $this->item->fields['entities_id']
      ];

      if (!$ios->isNewItem()) {
         //OS exists, check for updates
         $same = true;
         foreach ($input_os as $key => $value) {
            if ($ios->fields[$key] != $value) {
               $same = false;
               break;
            }
         }
         if ($same === false) {
            $ios->update(['id' => $ios->getID()] + $input_os);
         }
      } else {
         //$input_os['_no_history'] = $no_history;
         $ios->add($input_os);
      }
   }

   public function checkConf(Conf $conf) {
      $this->manage_osname = $conf->manage_osname;
      return true;
   }
}
