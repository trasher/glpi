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

use Glpi\Inventory\Conf;

class OperatingSystem extends InventoryAsset
{
   protected $extra_data = ['hardware' => null];
   private $manage_osname = false;
   private $operatingsystems_id;

   public function prepare() :array {
      if ($this->item->getType() != 'Computer') {
         throw new \RuntimeException('Operating systems are handled for computers only.');
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

      if (isset($this->extra_data['hardware'])) {
         if (property_exists($this->extra_data['hardware'], 'winprodid')) {
            $val->licenseid = $this->extra_data['hardware']->winprodid;
         }

         if (property_exists($this->extra_data['hardware'], 'winprodkey')) {
            $val->license_number = $this->extra_data['hardware']->winprodkey;
         }

         if (property_exists($this->extra_data['hardware'], 'osname')) {
            $val->full_name = $this->extra_data['hardware']->osname;
         }

         if (property_exists($this->extra_data['hardware'], 'osversion')) {
            $val->version = $this->extra_data['hardware']->osversion;
         }

         if (property_exists($this->extra_data['hardware'], 'oscomments')
                  && $this->extra_data['hardware']->oscomments != ''
                  && !strstr($this->extra_data['hardware']->oscomments, 'UTC')) {
            $val->service_pack = $this->extra_data['hardware']->oscomments;
         }
      }

      $val->operatingsystemeditions_id = '';
      if (property_exists($val, 'full_name') && $this->manage_osname == true) {
         $this->guessOSProperties($val);
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
         'operatingsystemarchitectures_id'   => $val->operatingsystemarchitectures_id ?? 0,
         'operatingsystemkernelversions_id'  => $val->operatingsystemkernelversions_id ?? 0,
         'operatingsystems_id'               => $val->operatingsystems_id,
         'operatingsystemversions_id'        => $val->operatingsystemversions_id ?? 0,
         'operatingsystemservicepacks_id'    => $val->operatingsystemservicepacks_id ?? 0,
         'operatingsystemeditions_id'        => $val->operatingsystemeditions_id ?? 0,
         'licenseid'                         => $val->licenseid ?? '',
         'license_number'                    => $val->license_number ?? '',
         'is_dynamic'                        => 1,
         'entities_id'                       => $this->item->fields['entities_id']
      ];

      $this->withHistory(true);//always store history for OS
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
            $ios->update(['id' => $ios->getID()] + $input_os, $this->withHistory());
         }
      } else {
         $ios->add($input_os, $this->withHistory());
      }

      $val->operatingsystems_id = $ios->fields['id'];;
      $this->operatingsystems_id = $val->operatingsystems_id;
   }

   public function checkConf(Conf $conf) {
      $this->manage_osname = $conf->manage_osname;
      return true;
   }

   /**
    * Guess operating system properties
    *
    * @param $val Operating system informations
    *
    * @return void
    */
   public function guessOSProperties(\stdClass &$val) {
      if (stripos($val->full_name, 'Linux')) {
         $this->guessLinux($val);
      } else {
         $this->guessWindows($val);
      }
   }

   /**
    * Guess OS properties for Windows
    *
    * @param $val Operating system informations
    *
    * @return void
    */
   public function guessWindows(\stdClass $val) {
      $matches = [];
      preg_match("/.+ Windows (XP |\d\.\d |\d{1,4} |Vista(™)? )(.*)/", $val->full_name, $matches);
      $matches = array_map('trim', $matches);
      if (count($matches) == 4) {
         $val->operatingsystemeditions_id = $matches[3];
         if (!property_exists($val, 'operatingsystemversions_id') || $val->operatingsystemversions_id == '') {
            $matches[1] = trim($matches[1]);
            if ($matches[2] != '') {
               $matches[1] = trim($matches[1], $matches[2]);
            }
            $val->operatingsystemversions_id = $matches[1];
         }
         return;
      }

      if (count($matches) == 2) {
         $val->operatingsystemeditions_id = $matches[1];
         return;
      }

      $matches = [];
      preg_match("/\w[\s\S]{0,4} (?:Windows[\s\S]{0,4} |)(.*) (\d{4} R2|\d{4})(?:, | |)(.*|)$/", $val->full_name, $matches);
      $matches = array_map('trim', $matches);
      if (count($matches) == 4) {
         $val->operatingsystemversions_id = $matches[2];
         $val->operatingsystemeditions_id = trim(sprintf('%s %s', $matches[1], $matches[3]));
      } else if ($val->full_name == 'Microsoft Windows Embedded Standard') {
         $val->operatingsystemeditions_id = 'Embedded Standard';
      } else if (empty($val->operatingsystems_id)) {
         $val->operatingsystems_id = $val->full_name;
      }
   }

   /**
    * Guess OS properties for Linux
    *
    * @param $val Operating system informations
    *
    * @return void
    */
   public function  guessLinux(\stdClass $val) {
      $matches = [];
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
         return;
      }

      $matches = [];
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
         return;
      }
   }

   /**
    * Get current OS id
    *
    * @return integer
    */
   public function getId() {
      return $this->operatingsystems_id;
   }
}
