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

class Software extends InventoryAsset
{
   const SEPARATOR = '$$$$';

   private $softList = [];
   private $softVersionList = [];

   public function prepare() :array {
      /*
      * Sometimes we can have 2 same software, but one without manufacturer and
      * one with. So in this case, delete the software without manufacturer
      */

      $mapping = [
         'publisher'       => 'manufacturers_id',
         'comments'        => 'comment',
         'installdate'     => 'date_install',
         'system_category' => '_system_category'
      ];

      //Dictionnary for softwares
      $rulecollection = new \RuleDictionnarySoftwareCollection();
      $manufacturers = []; //"cache"
      $softwares = [];
      //softs without manufacturer
      $nomanufacturer = [];
      //softs with manufacturers
      $found_manufacturers = [];

      //By default, no operating system is set
      $operatingsystems_id = 0;
      //Get the operating system of the computer if set
      //TODO
      /*if (isset($a_inventory['fusioninventorycomputer']['items_operatingsystems_id']['operatingsystems_id'])) {
         $operatingsystems_id = $a_inventory['fusioninventorycomputer']['items_operatingsystems_id']['operatingsystems_id'];
      }*/

      //Get the default entity for softwares, as defined in the entity's
      //configuration
      $entities_id = 0; //FIXME
      $entities_id_software = \Entity::getUsedConfig(
         'entities_id_software',
         $entities_id
      );

      //By default a software is not recursive
      $is_recursive = 0;

      //Count the number of software dictionnary rules
      $count_rules = \countElementsInTable("glpi_rules",
         [
            'sub_type'  => 'RuleDictionnarySoftware',
            'is_active' => 1,
         ]
      );
      //Configuration says that software can be created in the computer's entity
      if ($entities_id_software < 0) {
         $entities_id_software = $entities_id;
      } else {
         //Software will be created in an entity which is not the computer's entity.
         //It should be set as recursive
         $is_recursive = 1;
      }

      foreach ($this->data as $k => &$val) {
         foreach ($mapping as $origin => $dest) {
            if (property_exists($val, $origin)) {
               $val->$dest = $val->$origin;
            }
         }

         //If the PUBLISHER field is an array, get the first value only
         //TODO : document when it can happened. A FI or OCS agent bug ?
         if (property_exists($val, 'publisher') && is_array($val->publisher)) {
            $val->manufacturers_id = current($val->publisher);
         }

         if (!property_exists($val, 'name') || (property_exists($val, 'name') && $val->name == '')) {
            if (property_exists($val, 'guid') && $val->guid != '') {
               $val->name = $val->guid;
            }
         }
         $val->operatingsystems_id = $operatingsystems_id;

         //Check if the install date has the right format to be inserted in DB
         if (property_exists($val, 'date_install')) {
            $matches = [];
            preg_match("/^(\d{2})\/(\d{2})\/(\d{4})$/", $val->date_install, $matches);
            //This is the right date format : rewrite the date
            if (count($matches) == 4) {
               $val->date_install = $matches[3]."-".$matches[2]."-".$matches[1];
            } else {
               //Not the right format, remove the date
               unset($val->date_install);
            }
         }

         //If the software name exists and is defined
         if (property_exists($val, 'name') && $val->name != '') {
            $res_rule       = [];

            //Only play rules engine if there's at least one rule
            //for software dictionnary
            if ($count_rules > 0) {
               $rule_input = [
                  "name"               => $val->name,
                  "manufacturer"       => $val->manufacturers_id,
                  "old_version"        => $val->version,
                  "entities_id"        => $entities_id_software,
                  "_system_category"   => $val->_system_category
               ];
               $res_rule = $rulecollection->processAllRules($rule_input);
            }

            if (!isset($res_rule['_ignore_import'])
               || $res_rule['_ignore_import'] != 1) {

               //If the name has been modified by the rules engine
               if (isset($res_rule["name"])) {
                  $val->name = $res_rule["name"];
               }
               //If the version has been modified by the rules engine
               if (isset($res_rule["version"])) {
                  $val->version = $res_rule["version"];
               }
               //If the manufacturer has been modified or set by the rules engine
               if (isset($res_rule["manufacturer"])) {
                  $val->manufacturers_id = \Dropdown::import("Manufacturer", ['name' => $res_rule["manufacturer"]]);
               } else if (property_exists($val, 'manufacturers_id')
                  && $val->manufacturers_id != ''
                  && $val->manufacturers_id != '0'
               ) {
                  //Add the current manufacturer to the cache of manufacturers
                  if (!isset($manufacturers[$val->manufacturers_id])) {
                     $entities_id = 0;
                     /*if (isset($_SESSION["plugin_fusioninventory_entity"])) {
                        $entities_id = $_SESSION["plugin_fusioninventory_entity"];
                     }*/
                     $new_value = \Dropdown::importExternal(
                        'Manufacturer',
                        $val->manufacturers_id,
                        $entities_id
                     );
                     $manufacturers[$val->manufacturers_id] = $new_value;
                  }
                  //Set the manufacturer using the cache
                  $val->manufacturers_id = $manufacturers[$val->manufacturers_id];
               } else {
                  //Manufacturer not defined : set it's value to 0
                  $val->manufacturers_id = 0;
               }

               //The rules engine has modified the entity
               //(meaning that the software is recursive and defined
               //in an upper entity)
               if (isset($res_rule['new_entities_id'])) {
                  $val->entities_id = $res_rule['new_entities_id'];
                  $is_recursive    = 1;
               }

               //The entity has not been modified and is not set :
               //use the computer's entity
               if (!property_exists($val, 'entities_id')
                        || $val->entities_id == '') {
                  $val->entities_id = $entities_id_software;
               }
               //version is undefined, set it to blank
               if (!property_exists($val, 'version')) {
                  $val->version = '';
               }
               //This is a realy computer, not a template
               $val->is_template_item = 0;

               //The computer is not deleted
               $val->is_deleted_item = 0;

               //Store if the software is recursive or not
               $val->is_recursive = $is_recursive;

               //Step 1 : test using the old format
               //String with the manufacturer
               $comp_key = strtolower($val->name).
                              self::SEPARATOR.strtolower($val->version).
                              self::SEPARATOR.$val->manufacturers_id.
                              self::SEPARATOR.$val->entities_id.
                              self::SEPARATOR.$val->operatingsystems_id;

               //String without the manufacturer
               $comp_key_simple = strtolower($val->name).
                              self::SEPARATOR.strtolower($val->version).
                              self::SEPARATOR.$val->entities_id.
                              self::SEPARATOR.$val->operatingsystems_id;

               //String without the manufacturer
               ////TODO: do not really understand original code
               /*$comp_key_noos = strtolower($val->name).
                           self::SEPARATOR.strtolower($val->version).
                           self::SEPARATOR.$val->manufacturers_id.
                           self::SEPARATOR.$val->entities_id.
                           self::SEPARATOR.'0';
               $array_tmp['comp_key_noos'] = $comp_key_noos;*/

               if ($val->manufacturers_id == 0) {
                  $nomanufacturer[$comp_key_simple] = $val;
                  unset($this->data[$k]);
               } else {
                  if (!isset($softwares[$comp_key])) {
                     $found_manufacturers[$comp_key_simple] = 1;
                     $softwares[$comp_key] = 1;
                  }
               }
            }
         }
      }

      //Browse all softwares without a manufacturer. If one exists with a manufacturer
      //the remove the one without
      foreach ($nomanufacturer as $key => $array_tmp) {
         if (!isset($found_manufacturers[$key])) {
            $comp_key = strtolower($array_tmp->name).
                         self::SEPARATOR.strtolower($array_tmp->version).
                         self::SEPARATOR.$array_tmp->manufacturers_id.
                         self::SEPARATOR.$array_tmp->entities_id.
                         self::SEPARATOR.$array_tmp->operatingsystems_id;
            if (!isset($softwares[$comp_key])) {
               $softwares[$comp_key] = 1;
               $this->data[] = $array_tmp;
            }
         }
      }

      return $this->data;
   }

   public function handle() {
      global $DB;

      //By default entity  = root
      $entities_id  = 0;
      $computers_id = $this->item->fields['id'];

      $software                = new \Software();
      $softwareversion         = new \SoftwareVersion();
      $computerSoftwareversion = new \Item_SoftwareVersion();

      //Try to guess the entity of the software
      if (count($this->data)) {
         //Get the first software of the list
         $soft = current($this->data);
         //Get the entity of the first software : this info has been processed
         //in formatconvert, so it's either the computer's entity or
         //the entity as defined in the entity's configuration
         if (property_exists($soft, 'entities_id')) {
            $entities_id = $soft->entities_id;
         }
      }
      $db_software = [];
      $no_history = false; //FIXME

      //If we must take care of historical : it means we're not :
      //- at computer first inventory
      //- during the first inventory after an OS upgrade/change
      if ($no_history === false) {
         $iterator = $DB->request([
            'SELECT' => [
               'glpi_items_softwareversions.id as sid',
               'glpi_softwares.name',
               'glpi_softwareversions.name AS version',
               'glpi_softwares.manufacturers_id',
               'glpi_softwareversions.entities_id',
               'glpi_softwareversions.operatingsystems_id',
               'glpi_items_softwareversions.is_template_item',
               'glpi_items_softwareversions.is_deleted_item'
            ],
            'FROM'      => 'glpi_items_softwareversions',
            'LEFT JOIN' => [
               'glpi_softwareversions' => [
                  'ON'  => [
                     'glpi_items_softwareversions' => 'softwareversions_id',
                     'glpi_softwareversions'       => 'id'
                  ]
               ],
               'glpi_softwares'        => [
                  'ON'  => [
                     'glpi_softwareversions' => 'softwares_id',
                     'glpi_softwares'        => 'id'
                  ]
               ]
            ],
            'WHERE'     => [
               'glpi_items_softwareversions.items_id' => $this->item->fields['id'],
               'glpi_items_softwareversions.itemtype'    => $this->item->getType(),
               'glpi_items_softwareversions.is_dynamic'  => 1
            ]
         ]);

         while ($data = $iterator->next()) {
            $idtmp = $data['sid'];
            unset($data['sid']);
            //Escape software name if needed
            /*if (preg_match("/[^a-zA-Z0-9 \-_\(\)]+/", $data['name'])) {
               $data['name'] = Toolbox::addslashes_deep($data['name']);
            }
            //Escape software version if needed
            if (preg_match("/[^a-zA-Z0-9 \-_\(\)]+/", $data['version'])) {
               $data['version'] = Toolbox::addslashes_deep($data['version'];
            }*/
            $comp_key = strtolower($data['name']).
                         self::SEPARATOR.strtolower($data['version']).
                         self::SEPARATOR.$data['manufacturers_id'].
                         self::SEPARATOR.$data['entities_id'].
                         self::SEPARATOR.$data['operatingsystems_id'];
            $db_software[$comp_key] = $idtmp;
         }
      }

      $lastSoftwareid  = 0;
      $lastSoftwareVid = 0;

      /*
      * Schema
      *
      * LOCK software
      * 1/ Add all software
      * RELEASE software
      *
      * LOCK softwareversion
      * 2/ Add all software versions
      * RELEASE softwareversion
      *
      * 3/ add version to computer
      *
      */

      //$dbLock = new PluginFusioninventoryDBLock();

      if (count($db_software) == 0) { // there are no software associated with computer
         $nb_unicity = count(\FieldUnicity::getUnicityFieldsConfig("Software", $entities_id));
         $options    = [];
         //There's no unicity rules, do not enable this feature
         if ($nb_unicity == 0) {
            $options['disable_unicity_check'] = true;
         }

         $lastSoftwareid = $this->loadSoftwares($entities_id,
                                                $lastSoftwareid);

         //-----------------------------------
         //Step 1 : import softwares
         //-----------------------------------
         //Put a lock during software import for this computer
         //$dbLock->setLock('softwares');
         $this->loadSoftwares($entities_id,
                              $lastSoftwareid);

         //Browse softwares: add new software in database
         foreach ($this->data as $a_software) {
            if (!isset($this->softList[$a_software->name.self::SEPARATOR.
                     $a_software->manufacturers_id])) {
               $this->addSoftware($a_software, $options);
            }
         }
         //$dbLock->releaseLock('softwares');

         //-----------------------------------
         //Step 2 : import software versions
         //-----------------------------------
         $lastSoftwareVid = $this->loadSoftwareVersions($entities_id,
                                        $lastSoftwareVid);
         //$dbLock->setLock('softwareversions');
         $this->loadSoftwareVersions($entities_id,
                                     $lastSoftwareVid);
         foreach ($this->data as $a_software) {
            $softwares_id = $this->softList[$a_software->name
               .self::SEPARATOR.$a_software->manufacturers_id];
            if (!isset($this->softVersionList[strtolower($a_software->version)
            .self::SEPARATOR.$softwares_id
            .self::SEPARATOR.$a_software->operatingsystems_id])) {
               $this->addSoftwareVersion($a_software, $softwares_id);
            }
         }
         //$dbLock->releaseLock('softwareversions');

         $a_toinsert = [];
         foreach ($this->data as $a_software) {
            $softwares_id = $this->softList[$a_software->name
               .self::SEPARATOR.$a_software->manufacturers_id];
            $softwareversions_id = $this->softVersionList[strtolower($a_software->version)
               .self::SEPARATOR.$softwares_id
               .self::SEPARATOR.$a_software->operatingsystems_id];
            $a_tmp = [
               'itemtype'              => $this->item->getType(),
                'items_id'             => $this->item->fields['id'],
                'softwareversions_id'  => $softwareversions_id,
                'is_dynamic'           => 1,
                'entities_id'          => $this->item->fields['entities_id'],
                'date_install'         => null
            ];
            //By default date_install is null: if an install date is provided,
            //we set it
            if (isset($a_software->date_install)) {
               $a_tmp['date_install'] = $a_software->date_install;
            }
            $a_toinsert[] = $a_tmp;
         }
         if (count($a_toinsert) > 0) {
            $this->addSoftwareVersionsComputer($a_toinsert);

            //Check if historical has been disabled for this software only
            $comp_key = strtolower($a_software->name).
                         self::SEPARATOR.strtolower($a_software->version).
                         self::SEPARATOR.$a_software->manufacturers_id.
                         self::SEPARATOR.$a_software->entities_id.
                         self::SEPARATOR.$a_software->operatingsystems_id;
            if (property_exists($a_software, 'no_history') && $a_software->no_history) {
               $no_history_for_this_software = true;
            } else {
               $no_history_for_this_software = false;
            }

            /*if (!$no_history && !$no_history_for_this_software) {
               foreach ($this->data as $a_software) {
                  $softwares_id = $this->softList[$a_software->name
                     .self::SEPARATOR.$a_software->manufacturers_id];
                  $softwareversions_id = $this->softVersionList[strtolower($a_software->version)
                     .self::SEPARATOR.$softwares_id
                     .self::SEPARATOR.$a_software->operatingsystems_id];

                  $changes[0] = '0';
                  $changes[1] = "";
                  $changes[2] = $a_software->name." - ".
                          sprintf(__('%1$s (%2$s)'), $a_software->version, $softwareversions_id);
                  $this->addPrepareLog($computers_id, 'Computer', 'SoftwareVersion', $changes,
                               Log::HISTORY_INSTALL_SOFTWARE);

                  $changes[0] = '0';
                  $changes[1] = "";
                  $changes[2] = sprintf(__('%1$s (%2$s)'), $computer->getName(), $computers_id);
                  $this->addPrepareLog($softwareversions_id, 'SoftwareVersion', 'Computer', $changes,
                               Log::HISTORY_INSTALL_SOFTWARE);
               }
            }*/
         }

      } else {

         //It's not the first inventory, or not an OS change/upgrade

         //Do software migration first if needed
         //FIXME: not sure
         //$a_inventory = $this->migratePlatformForVersion($a_inventory, $db_software);

         //If software exists in DB, do not process it
         foreach ($this->data as $key => $arrayslower) {
            //Software installation already exists for this computer ?
            if (isset($db_software[$key])) {
               //It exists: remove the software from the key
               unset($this->data[$key]);
               unset($db_software[$key]);
            }
         }

         if (count($this->data) > 0
            || count($db_software) > 0) {
            if (count($db_software) > 0) {
               // Delete softwares in DB
               foreach ($db_software as $idtmp) {

                  if (isset($this->installationWithoutLogs[$idtmp])) {
                     $no_history_for_this_software = true;
                  } else {
                     $no_history_for_this_software = false;
                  }
                  $computerSoftwareversion->getFromDB($idtmp);
                  $softwareversion->getFromDB($computerSoftwareversion->fields['softwareversions_id']);

                  /*if (!$no_history && !$no_history_for_this_software) {
                     $changes[0] = '0';
                     $changes[1] = addslashes($computerSoftwareversion->getHistoryNameForItem1($softwareversion, 'delete'));
                     $changes[2] = "";
                     $this->addPrepareLog($computers_id, 'Computer', 'SoftwareVersion', $changes,
                                  Log::HISTORY_UNINSTALL_SOFTWARE);

                     $changes[0] = '0';
                     $changes[1] = sprintf(__('%1$s (%2$s)'), $computer->getName(), $computers_id);
                     $changes[2] = "";
                     $this->addPrepareLog($idtmp, 'SoftwareVersion', 'Computer', $changes,
                                  Log::HISTORY_UNINSTALL_SOFTWARE);
                  }*/
               }
               $DB->delete(
                  'glpi_items_softwareversions', [
                  'id' => $db_software
                  ]
               );
            }
            if (count($this->data)) {
               $nb_unicity = count(\FieldUnicity::getUnicityFieldsConfig("Software",
                                                                        $entities_id));
               $options = [];
               if ($nb_unicity == 0) {
                  $options['disable_unicity_check'] = true;
               }
               $lastSoftwareid = $this->loadSoftwares($entities_id, $lastSoftwareid);

               //$dbLock->setLock('softwares');
               $this->loadSoftwares($entities_id, $lastSoftwareid);
               foreach ($this->data as $a_software) {
                  if (!isset($this->softList[$a_software->name.self::SEPARATOR.
                           $a_software->manufacturers_id])) {
                     $this->addSoftware($a_software,
                                        $options);
                  }
               }
               //$dbLock->releaseLock('softwares');

               $lastSoftwareVid = $this->loadSoftwareVersions($entities_id,
                                              $lastSoftwareVid);
               //$dbLock->setLock('softwareversions');
               $this->loadSoftwareVersions($entities_id,
                                           $lastSoftwareVid);
               foreach ($this->data as $a_software) {
                  $softwares_id = $this->softList[$a_software->name.self::SEPARATOR.$a_software->manufacturers_id];
                  if (!isset($this->softVersionList[strtolower($a_software->version).self::SEPARATOR.$softwares_id.self::SEPARATOR.$a_software->operatingsystems_id])) {
                     $this->addSoftwareVersion($a_software, $softwares_id);
                  }
               }
               //$dbLock->releaseLock('softwareversions');

               $a_toinsert = [];
               foreach ($this->data as $key => $a_software) {
                  //Check if historical has been disabled for this software only
                  if (property_exists($a_software, 'no_history') && $a_software->no_history) {
                     $no_history_for_this_software = true;
                  } else {
                     $no_history_for_this_software = false;
                  }
                  $softwares_id = $this->softList[$a_software->name.self::SEPARATOR.$a_software->manufacturers_id];
                  $softwareversions_id = $this->softVersionList[strtolower($a_software->version).self::SEPARATOR.$softwares_id.self::SEPARATOR.$a_software->operatingsystems_id];
                  $a_tmp = [
                     'itemtype'            => $this->item->getType(),
                     'items_id'            => $this->item->fields['id'],
                     'softwareversions_id' => $softwareversions_id,
                     'is_dynamic'          => 1,
                     'entities_id'         => $this->item->fields['entities_id'],
                     'date_install'        => 'NULL'
                  ];
                  if (property_exists($a_software, 'date_install')) {
                     $a_tmp['date_install'] = $a_software->date_install;
                  }
                  $a_toinsert[] = $a_tmp;
               }
               $this->addSoftwareVersionsComputer($a_toinsert);

               /*if (!$no_history && !$no_history_for_this_software) {
                  foreach ($a_inventory['software'] as $a_software) {
                     $softwares_id = $this->softList[$a_software['name'].self::SEPARATOR.$a_software['manufacturers_id']];
                     $softwareversions_id = $this->softVersionList[strtolower($a_software['version']).self::SEPARATOR.$softwares_id.self::SEPARATOR.$a_software['operatingsystems_id']];

                     $changes[0] = '0';
                     $changes[1] = "";
                     $changes[2] = $a_software['name']." - ".
                           sprintf(__('%1$s (%2$s)'), $a_software['version'], $softwareversions_id);
                     $this->addPrepareLog($computers_id, 'Computer', 'SoftwareVersion', $changes,
                                  Log::HISTORY_INSTALL_SOFTWARE);

                     $changes[0] = '0';
                     $changes[1] = "";
                     $changes[2] = sprintf(__('%1$s (%2$s)'), $computer->getName(), $computers_id);
                     $this->addPrepareLog($softwareversions_id, 'SoftwareVersion', 'Computer', $changes,
                                  Log::HISTORY_INSTALL_SOFTWARE);
                  }
               }*/
            }
         }
      }
   }

   /**
    * Load softwares from database that are matching softwares coming from the
    * currently processed inventory
    *
    * @global object $DB
    * @param integer $entities_id entitity id
    * @param integer $lastid last id search to not search from beginning
    * @return integer last software id
    */
   private function loadSoftwares($entities_id, $lastid = 0) {
      global $DB;

      $whereid = '';
      if ($lastid > 0) {
         $whereid .= ' AND `id` > "'.$lastid.'"';
      }
      $a_softSearch = [];
      $nbSoft = 0;
      if (count($this->softList) == 0) {
         foreach ($this->data as $a_software) {
            $a_softSearch[] = "'".$a_software->name.self::SEPARATOR.$a_software->manufacturers_id."'";
            $nbSoft++;
         }
      } else {
         foreach ($this->data as $a_software) {
            if (!isset($this->softList[$a_software->name.self::SEPARATOR.$a_software->manufacturers_id])) {
               $a_softSearch[] = "'".$a_software->name.self::SEPARATOR.$a_software->manufacturers_id."'";
               $nbSoft++;
            }
         }
      }
      $whereid .= " AND CONCAT_WS('".self::SEPARATOR."', `name`, `manufacturers_id`) IN (".implode(",", $a_softSearch).")";

      $sql     = "SELECT max( id ) AS max FROM `glpi_softwares`";
      $result  = $DB->query($sql);
      $data    = $DB->fetchAssoc($result);
      $lastid  = $data['max'];
      $whereid.= " AND `id` <= '".$lastid."'";
      if ($nbSoft == 0) {
         return $lastid;
      }

      $sql = "SELECT `id`, `name`, `manufacturers_id`
              FROM `glpi_softwares`
              WHERE `entities_id`='".$entities_id."'".$whereid;
      foreach ($DB->request($sql) as $data) {
         $this->softList[$data['name'].self::SEPARATOR.$data['manufacturers_id']] = $data['id'];
      }
      return $lastid;
   }

   /**
    * Load software versions from DB are in the incomming inventory
    *
    * @global object $DB
    * @param integer $entities_id entitity id
    * @param integer $lastid last id search to not search from beginning
    * @return integer last software version id
    */
   private function loadSoftwareVersions($entities_id, $lastid = 0) {
      global $DB;

      $whereid = '';
      if ($lastid > 0) {
         $whereid .= ' AND `id` > "'.$lastid.'"';
      }
      $arr = [];
      $a_versions = [];
      foreach ($this->data as $a_software) {
         $softwares_id = $this->softList[$a_software->name.self::SEPARATOR.$a_software->manufacturers_id];
         if (!isset($this->softVersionList[strtolower($a_software->version).self::SEPARATOR.$softwares_id.self::SEPARATOR.$a_software->operatingsystems_id])) {
            $a_versions[$a_software->version][] = $softwares_id;
         }
      }

      $nbVersions = 0;
      foreach ($a_versions as $name=>$a_softwares_id) {
         foreach ($a_softwares_id as $softwares_id) {
            $arr[] = "'".$name.self::SEPARATOR.$softwares_id.self::SEPARATOR.$a_software->operatingsystems_id."'";
         }
         $nbVersions++;
      }
      $whereid .= " AND CONCAT_WS('".self::SEPARATOR."', `name`, `softwares_id`, `operatingsystems_id`) IN ( ";
      $whereid .= implode(',', $arr);
      $whereid .= " ) ";

      $sql = "SELECT max( id ) AS max FROM `glpi_softwareversions`";
      $result = $DB->query($sql);
      $data = $DB->fetchAssoc($result);
      $lastid = $data['max'];
      $whereid .= " AND `id` <= '".$lastid."'";

      if ($nbVersions == 0) {
         return $lastid;
      }

      $sql = "SELECT `id`, `name`, `softwares_id`, `operatingsystems_id` FROM `glpi_softwareversions`
      WHERE `entities_id`='".$entities_id."'".$whereid;
      $result = $DB->query($sql);
      while ($data = $DB->fetchAssoc($result)) {
         $this->softVersionList[strtolower($data['name']).self::SEPARATOR.$data['softwares_id'].self::SEPARATOR.$data['operatingsystems_id']] = $data['id'];
      }

      return $lastid;
   }

   /**
    * Add a new software
    *
    * @param array $a_software
    * @param array $options
    */
   private function addSoftware($a_software, $options) {
      $software = new \Software();
      $a_softwares_id = $software->add((array)$a_software, $options, false);
      //$this->addPrepareLog($a_softwares_id, 'Software');

      $this->softList[$a_software->name.self::SEPARATOR.$a_software->manufacturers_id] = $a_softwares_id;
   }

   /**
    * Add a software version
    *
    * @param array $a_software
    * @param integer $softwares_id
    */
   private function addSoftwareVersion($a_software, $softwares_id) {

      $options = [];
      $options['disable_unicity_check'] = true;

      $softwareVersion = new \SoftwareVersion();
      $a_software->name          = $a_software->version;
      $a_software->softwares_id  = $softwares_id;
      $a_software->_no_history   = true;
      $softwareversions_id = $softwareVersion->add((array)$a_software, $options, false);
      //$this->addPrepareLog($softwareversions_id, 'SoftwareVersion');
      $this->softVersionList[strtolower($a_software->version)."$$$$".$softwares_id."$$$$".$a_software->operatingsystems_id] = $softwareversions_id;
   }

   /**
    * Link software versions with the computer
    *
    * @global object $DB
    * @param array $a_input
    */
   private function addSoftwareVersionsComputer($a_input) {
      global $DB;

      $insert_query = $DB->buildInsert(
         'glpi_items_softwareversions', [
            'itemtype'              => 'Computer',
            'items_id'              => new \QueryParam(),
            'softwareversions_id'   => new \QueryParam(),
            'is_dynamic'            => new \QueryParam(),
            'entities_id'           => new \QueryParam(),
            'date_install'          => new \QueryParam()
         ]
      );
      $stmt = $DB->prepare($insert_query);

      foreach ($a_input as $input) {
         $stmt->bind_param(
            'sssss',
            $input['items_id'],
            $input['softwareversions_id'],
            $input['is_dynamic'],
            $input['entities_id'],
            $input['date_install']
         );
         $stmt->execute();
      }
      mysqli_stmt_close($stmt);
   }

   public function checkConf(Conf $conf) {
      return $conf->import_software == 1;
   }
}
