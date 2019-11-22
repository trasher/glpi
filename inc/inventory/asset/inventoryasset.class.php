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

abstract class InventoryAsset
{
   /** @var array */
   protected $data;
   /** @var CommonDBTM */
   protected $item;
   /** @var array */
   protected $extra_data = [];

   /**
    * Constructor
    *
    * @param array $data Data part, optional
    */
   public function __construct(\CommonDBTM $item, array $data = null) {
      $this->item = $item;
      if ($data !== null) {
         $this->data = $data;
      }
   }

   /**
    * Set data from raw data part
    *
    * @param array $data Data part
    *
    * @return InventoryAsset
    */
   public function setData(array $data) {
      $this->data = $data;
      return $this;
   }

   /**
    * Prepare data from raw data part
    */
   abstract public function prepare() :array;

   /**
    * Handle in database
    */
   abstract public function handle();

   /**
    * Set extra sub parts of interest
    * Only declared types in subclass extra_data are handled
    *
    * @param array $data Processed data
    *
    * @return InventoryAsset
    */
   public function setExtraData($data) {
      foreach (array_keys($this->extra_data) as $extra) {
         if (isset($data[$extra])) {
            $this->extra_data[$extra] = $data[$extra];
         }
      }
      return $this;
   }

   /**
    * Get ignore list declared from asset
    *
    * @param string $type Ignore type ("controllers" only for now)
    *
    * @return array
    */
   public function getIgnored($type) {
      return $this->ignored[$type] ?? [];
   }

   /**
    * Check if configuration allows that part
    *
    * @param Conf $conf Conf instance
    *
    * @return boolean
    */
   abstract public function checkConf(Conf $conf);

   /**
    * Handle links (manufacturers, models, users, ...), create items if needed
    *
    * @return array
    */
   public function handleLinks() {
      //$a_lockable = PluginFusioninventoryLock::getLockFields(getTableForItemType($itemtype), $items_id);

      $ignored = [
         'software',
         'ipaddress',
         'internalport'
      ];

      foreach ($this->data as &$value) {
         // save raw manufacture name before its replacement by id for importing model
         // (we need manufacturers name in when importing model in dictionary)
         $manufacture_name = "";
         if (property_exists($value, 'manufacturers_id')) {
            $manufacture_name = $value->manufacturers_id;
         }

         foreach ($value as $key => $val) {
            if (true/*!PluginFusioninventoryLock::isFieldLocked($a_lockable, $key)*/) {
               if ($key == "manufacturers_id" || $key == 'bios_manufacturers_id') {
                  $manufacturer = new \Manufacturer();
                  $value->$key  = $manufacturer->processName($value->$key);
                  if ($key == 'bios_manufacturers_id') {
                     $this->foreignkey_itemtype[$key] = getItemtypeForForeignKeyField('manufacturers_id');
                  } else {
                     /*if (isset($CFG_GLPI['plugin_fusioninventory_computermanufacturer'][$value])) {
                        $CFG_GLPI['plugin_fusioninventory_computermanufacturer'][$value] = $array[$key];
                     }*/
                  }
               }
               if (!is_numeric($key)) {
                  $entities_id = 0;
                  /*if (isset($_SESSION["plugin_fusioninventory_entity"])) {
                     $entities_id = $_SESSION["plugin_fusioninventory_entity"];
                  }*/
                  if ($key == "locations_id") {
                     $value->$key = \Dropdown::importExternal('Location', $value->$key, $entities_id);
                  } else if ($key == "computermodels_id") {
                     // computer model need manufacturer relation for dictionary import
                     // see \CommonDCModelDropdown::$additional_fields_for_dictionnary
                     $value->$key = \Dropdown::importExternal('ComputerModel', $value->$key, $entities_id, [
                        'manufacturer' => $manufacture_name
                     ]);
                  } else if (isset($this->foreignkey_itemtype[$key])) {
                     $value->$key = \Dropdown::importExternal($this->foreignkey_itemtype[$key], $value->$key, $entities_id);
                  } else if (isForeignKeyField($key) && $key != "users_id") {
                     $this->foreignkey_itemtype[$key] = getItemtypeForForeignKeyField($key);
                     $value->$key = \Dropdown::importExternal($this->foreignkey_itemtype[$key], $value->$key, $entities_id);

                     if ($key == 'operatingsystemkernelversions_id'
                        && property_exists($value, 'operatingsystemkernels_id')
                        && (int)$value->$key > 0
                     ) {
                        $kversion = new \OperatingSystemKernelVersion();
                        $kversion->getFromDB($value->$key);
                        if ($kversion->fields['operatingsystemkernels_id'] != $value->operatingsystemkernels_id) {
                           $kversion->update([
                              'id'                          => $kversion->getID(),
                              'operatingsystemkernels_id'   => $value->operatingsystemkernels_id
                           ]);
                        }
                     }
                  }
               }
            } else {
               unset($value->$key);
            }
         }
      }
      return $this->data;
   }
}
