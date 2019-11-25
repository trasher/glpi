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

class Computer extends InventoryAsset
{
   protected $extra_data = [
      'hardware'     => null,
      'accountinfo'  => null,
      'bios'         => null,
      'users'        => null,
      'assets'       => null
   ];
   private $raw_data;
   private $hardware;

   public function __construct(\CommonDBTM $item, $data) {
      $this->item = $item;
      //store raw data to be included in a file on disk
      $this->raw_data = $data;
   }

   public function prepare() :array {
      global $DB;

      $mapping = [];

      $val = new \stdClass();

      if (isset($this->extra_data['hardware'])) {
         $hardware = (object)$this->extra_data['hardware'];

         $hw_mapping = [
            'name'           => 'name',
            'winprodid'      => 'licenseid',
            'winprodkey'     => 'license_number',
            'workgroup'      => 'domains_id',
            'uuid'           => 'uuid',
            'lastloggeduser' => 'users_id',
         ];

         foreach ($hw_mapping as $origin => $dest) {
            if (property_exists($hardware, $origin)) {
               $hardware->$dest = $hardware->$origin;
            }
         }
         $this->hardware = $hardware;

         foreach ($hardware as $key => $property) {
            $val->$key = $property;
         }
      }

      if (property_exists($val, 'users_id')) {
         if ($val->users_id == '') {
            unset($val->users_id);
         } else {
            $val->contact = $val->users_id;
            $split_user = explode("@", $val->users_id);
            $iterator = $DB->request([
               'SELECT' => 'id',
               'FROM'   => 'glpi_users',
               'WHERE'  => [
                  'name'   => $split_user[0]
               ],
               'LIMIT'  => 1
            ]);
            $result = $iterator->next();
            $query = "SELECT `id`
                      FROM `glpi_users`
                      WHERE `name` = '" . $split_user[0] . "'
                      LIMIT 1";
            if (count($iterator)) {
               $result = $DB->query($query);
               $val->users_id = $result['id'];
            } else {
               $val->users_id = 0;
            }
         }
      }

      // * BIOS
      if (isset($this->extra_data['bios'])) {
         $bios = (object)$this->extra_data['bios'];
         if (property_exists($bios, 'assettag')
               && !empty($bios->assettag)) {
            $val->otherserial = $bios->assettag;
         }
         if (property_exists($bios, 'smanufacturer')
               && !empty($bios->smanufacturer)) {
            $val->manufacturers_id = $bios->smanufacturer;
         } else if (property_exists($bios, 'mmanufacturer')
               && !empty($bios->mmanufacturer)) {
            $val->manufacturers_id = $bios->mmanufacturer;
            $val->mmanufacturer = $bios->mmanufacturer;
         } else if (property_exists($bios, 'bmanufacturer')
               && !empty($bios->bmanufacturer)) {
            $val->manufacturers_id = $bios->bmanufacturer;
            $val->bmanufacturer = $bios->bmanufacturer;
         }

         if (property_exists($bios, 'smodel') && $bios->smodel != '') {
            $val->computermodels_id = $bios->smodel;
         } else if (property_exists($bios, 'mmodel') && $bios->mmodel != '') {
            $val->computermodels_id = $bios->mmodel;
            $val->model = $bios->mmodel;
         }

         if (property_exists($bios, 'ssn')) {
            $val->serial = trim($bios->ssn);
            // HP patch for serial begin with 'S'
            if (property_exists($val, 'manufacturers_id')
                  && strstr($val->manufacturers_id, "ewlett")
                  && preg_match("/^[sS]/", $val->serial)) {
               $val->serial = trim(
                  preg_replace(
                     "/^[sS]/",
                     "",
                     $val->serial
                  )
               );
            }
         }

         if (property_exists($bios, 'msn')) {
            $val->mserial = $bios->msn;
         }
      }

      // otherserial (on tag) if defined in config
      // TODO: andle config
      if (!property_exists($val, 'otherserial')) {
         if (isset($this->extra_data['accountinfo'])) {
            $ainfos = (object)$this->extra_data['accountinfo'];
            if ($ainfos->keyname == 'TAG' && $ainfos->keyvalue != '') {
               $val->otherserial = $ainfos->keyvalue;
            }
         }
      }

      // * Type of computer
      if (isset($hardware)) {
         if (property_exists($hardware, 'vmsystem')
            && $hardware->vmsystem != ''
            && $hardware->vmsystem != 'Physical'
         ) {
            //First the HARDWARE/VMSYSTEM is not Physical : then it's a virtual machine
            $val->computertypes_id = $hardware->vmsystem;
            // HACK FOR BSDJail, remove serial and UUID (because it's of host, not contener)
            if ($hardware->vmsystem == 'BSDJail') {
               if (property_exists($val, 'serial')) {
                  $val->serial = '';
               }
               $val->uuid .= '-' . $val->name;
            }
         } else {
            //It's not a virtual machine, then check :
            //1 - HARDWARE/CHASSIS_TYPE
            //2 - BIOS/TYPE
            //3 - BIOS/MMODEL
            //4 - HARDWARE/VMSYSTEM (should not go there)
            if (property_exists($hardware, 'chassis_type')
                  && !empty($hardware->chassis_type)) {
               $val->computertypes_id = $hardware->chassis_type;
            } else if (isset($bios) && property_exists($bios, 'type')
                  && !empty($bios->type)) {
               $val->computertypes_id = $bios->type;
            } else if (isset($bios) && property_exists($bios, 'mmodel')
                  && !empty($bios->mmodel)) {
               $val->computertypes_id = $bios->mmodel;
            } else if (property_exists($hardware, 'vmsystem')
                  && !empty($hardware->vmsystem)) {
               $val->computertypes_id = $hardware->vmsystem;
            }
         }
      }

      // * USERS
      $cnt = 0;
      if (isset($this->extra_data['users'])) {
         if (count($this->extra_data['users']) > 0) {
            $user_temp = '';
            if (property_exists($val, 'contact')) {
               $user_temp = $val->contact;
            }
            $val->contact = '';
         }
         foreach ($this->extra_data['users'] as $a_users) {
            $user = '';
            if (property_exists($a_users, 'login')) {
               $user = $a_users->login;
               if (property_exists($a_users, 'domain')
                       && !empty($a_users->domain)) {
                  $user .= "@" . $a_users->domain;
               }
            }
            if ($cnt == 0) {
               if (property_exists($a_users, 'login')) {
                  // Search on domain
                  $where_add = [];
                  if (property_exists($a_users, 'domain')
                          && !empty($a_users->domain)) {
                     $ldaps = $DB->request('glpi_authldaps',
                           ['WHERE'  => ['inventory_domain' => $a_users->domain]]
                     );
                     $ldaps_ids = [];
                     foreach ($ldaps as $data_LDAP) {
                        $ldaps_ids[] = $data_LDAP['id'];
                     }
                     if (count($ldaps_ids)) {
                        $where_add['authtype'] = Auth::LDAP;
                        $where_add['auths_id'] = $ldaps_ids;
                     }
                  }
                  $iterator = $DB->request([
                     'SELECT' => ['id'],
                     'FROM'   => 'glpi_users',
                     'WHERE'  => [
                        'name'   => $a_users->login
                     ] + $where_add,
                     'LIMIT'  => 1
                  ]);
                  if ($row = $iterator->next()) {
                     $val->users_id = $row['id'];
                  }
               }
            }

            if ($user != '') {
               if (property_exists($val, 'contact')) {
                  if ($val->contact == '') {
                     $val->contact = $user;
                  } else {
                     $val->contact .= "/" . $user;
                  }
               } else {
                  $val->contact = $user;
               }
            }
            $cnt++;
         }
         if (empty($val->contact)) {
            $val->contact = $user_temp;
         }
      }

      $this->data = [$val];

      // * Hacks

      // Hack to put OS in software
      /*if (isset($arrayinventory['CONTENT']['HARDWARE']['OSNAME'])) {
         $inputos = [];
         if (isset($arrayinventory['CONTENT']['HARDWARE']['OSCOMMENTS'])) {
            $inputos['COMMENTS'] = $arrayinventory['CONTENT']['HARDWARE']['OSCOMMENTS'];
         }
         $inputos['NAME']     = $arrayinventory['CONTENT']['HARDWARE']['OSNAME'];
         if (isset($arrayinventory['CONTENT']['HARDWARE']['OSVERSION'])) {
            $inputos['VERSION']  = $arrayinventory['CONTENT']['HARDWARE']['OSVERSION'];
         }
         if (isset($arrayinventory['CONTENT']['SOFTWARES']['VERSION'])) {
            $temparray = $arrayinventory['CONTENT']['SOFTWARES'];
            $arrayinventory['CONTENT']['SOFTWARES'] = [];
            $arrayinventory['CONTENT']['SOFTWARES'][] = $temparray;
         }
         $arrayinventory['CONTENT']['SOFTWARES'][] = $inputos;
      }*/

      // End hack

      return $this->data;
   }

   public function handle() {
      //$a_computerinventory = PluginFusioninventoryFormatconvert::computerInventoryTransformation( $arrayinventory['CONTENT']);

      $val = $this->data[0];
      $val->is_dynamic = 1;

      /*$pfBlacklist = new PluginFusioninventoryInventoryComputerBlacklist();
      $a_computerinventory = $pfBlacklist->cleanBlacklist($a_computerinventory);

      if (isset($a_computerinventory['monitor'])) {
         foreach ($a_computerinventory['monitor'] as $num=>$a_monit) {
            $a_computerinventory['monitor'][$num] = $pfBlacklist->cleanBlacklist($a_monit);
         }
      }*/
      //$this->fillArrayInventory($a_computerinventory);

      $input = [];

      // Global criterias

      $val = $this->data[0];
      if (property_exists($val, 'serial') && !empty($val->serial)) {
         $input['serial'] = $val->serial;
      }
      if (property_exists($val, 'otherserial') && !empty($val->otherserial)) {
         $input['otherserial'] = $val->otherserial;
      }
      if (property_exists($val, 'uuid') && !empty($val->uuid)) {
         $input['uuid'] = $val->uuid;
      }
      /*if (isset($this->device_id) && !empty($this->device_id)) {
         $input['device_id'] = $this->device_id;
      }*/

      /** FIXME: ensure to inject data */
      if (isset($this->extra_data['assets']['\Glpi\Inventory\Asset\NetworkCard'])) {
         foreach ($this->extra_data['assets']['\Glpi\Inventory\Asset\NetworkCard'] as $networkcard) {
            $netports = $networkcard->getNetworkPorts();
            foreach ($netports as $network) {
               if (property_exists($network, 'virtualdev')
                  && $network->virtualdev != 1
                  || !property_exists($network, 'virtualdev')
               ) {
                  if (property_exists($network, 'mac') && !empty($network->mac)) {
                     $input['mac'][] = $network->mac;
                  }
                  foreach ($network->ipaddress as $ip) {
                     if ($ip != '127.0.0.1' && $ip != '::1') {
                        $input['ip'][] = $ip;
                     }
                  }
                  if (property_exists($network, 'subnet') && !empty($network->subnet)) {
                     $input['subnet'][] = $network->subnet;
                  }
               }
            }

            // Case of virtualmachines
            if (!isset($input['mac'])
                     && !isset($input['ip'])) {
               foreach ($netports as $network) {
                  if (property_exists($network, 'mac') && !empty($network->mac)) {
                     $input['mac'][] = $network->mac;
                  }
                  foreach ($network->ipaddress as $ip) {
                     if ($ip != '127.0.0.1' && $ip != '::1') {
                        $input['ip'][] = $ip;
                     }
                  }
                  if (property_exists($network, 'subnet') && !empty($network->subnet)) {
                     $input['subnet'][] = $network->subnet;
                  }
               }
            }
         }
      }

      /*if ((isset($a_computerinventory['fusioninventorycomputer']['items_operatingsystems_id']['license_number']))
               AND (!empty($a_computerinventory['fusioninventorycomputer']['items_operatingsystems_id']['license_number']))) {
         $input['mskey'] = $a_computerinventory['fusioninventorycomputer']['items_operatingsystems_id']['license_number'];
      }
      if ((isset($a_computerinventory['fusioninventorycomputer']['items_operatingsystems_id']['operatingsystems_id']))
               AND (!empty($a_computerinventory['fusioninventorycomputer']['items_operatingsystems_id']['operatingsystems_id']))) {
         $input['osname'] = $a_computerinventory['fusioninventorycomputer']['items_operatingsystems_id']['operatingsystems_id'];
      }
      if ((isset($a_computerinventory['fusioninventorycomputer']['oscomment']))
               AND (!empty($a_computerinventory['fusioninventorycomputer']['oscomment']))) {
         $input['oscomment'] = $a_computerinventory['fusioninventorycomputer']['oscomment'];
      }*/

      if (property_exists($val, 'computermodels_id')
            && !empty($val->computermodels_id)
      ) {
         $input['model'] = $val->computermodels_id;
      }
      if (property_exists($val, 'domains_id')
            && !empty($val->domains_id)
      ) {
         $input['domains_id'] = $val->domains_id;
      }

      //$input['tag'] = $tagAgent;

      if (property_exists($val, 'name') && !empty($val->name)) {
         $input['name'] = $val->name;
      } else {
         $input['name'] = '';
      }
      $input['itemtype'] = $this->item->getType();

      // If transfer is disable, get entity and search only on this entity
      // (see http://forge.fusioninventory.org/issues/1503)

      // * entity rules
      // FIXME: Q&D hack
      $input['entities_id'] = 0;
      /*$inputent = $input;
      if ((isset($a_computerinventory['Computer']['domains_id']))
                    AND (!empty($a_computerinventory['Computer']['domains_id']))) {
         $inputent['domain'] = $a_computerinventory['Computer']['domains_id'];
      }
      if (isset($inputent['serial'])) {
         $inputent['serialnumber'] = $inputent['serial'];
      }
      $ruleEntity = new PluginFusioninventoryInventoryRuleEntityCollection();

      // * Reload rules (required for unit tests)
      $ruleEntity->getCollectionPart();

      $dataEntity = $ruleEntity->processAllRules($inputent, []);
      if (isset($dataEntity['_ignore_import'])) {
         return;
      }

      if (isset($dataEntity['entities_id'])
                    && $dataEntity['entities_id'] >= 0) {
         $_SESSION["plugin_fusioninventory_entity"] = $dataEntity['entities_id'];
         $input['entities_id'] = $dataEntity['entities_id'];

      } else if (isset($dataEntity['entities_id'])
                    && $dataEntity['entities_id'] == -1) {
         $input['entities_id'] = 0;
         $_SESSION["plugin_fusioninventory_entity"] = -1;
      } else {
         $input['entities_id'] = 0;
         $_SESSION["plugin_fusioninventory_entity"] = 0;
      }

      if (isset($dataEntity['locations_id'])) {
         $_SESSION['plugin_fusioninventory_locations_id'] = $dataEntity['locations_id'];
      }
         // End entity rules
      $_SESSION['plugin_fusioninventory_classrulepassed'] =
                     "PluginFusioninventoryInventoryComputerInventory";

      //Add the location if needed (play rule locations engine)
      $output = [];
      $output = PluginFusioninventoryToolbox::addLocation($input, $output);
      if (isset($output['locations_id'])) {
         $_SESSION['plugin_fusioninventory_locations_id'] =
               $output['locations_id'];
      }
      */

      //call rules on current collected data to find item
      //a callback on rulepassed() will be done if one is found.
      $rule = new \RuleImportComputerCollection();
      $rule->getCollectionPart();
      $data = $rule->processAllRules($input, [], ['class' => $this]);

      if (isset($data['_no_rule_matches']) AND ($data['_no_rule_matches'] == '1')) {
         //no rule matched, this is a new one
         $this->rulepassed(0, $this->item->getType());
      } else if (!isset($data['found_inventories'])) {
         //nothing found, this seems an unmanaged device
         $this->errors[] = 'Not managed device are not handled yet.';
         /*$pfIgnoredimportdevice = new PluginFusioninventoryIgnoredimportdevice();
         $inputdb = [];
         $inputdb['name'] = $input['name'];
         $inputdb['date'] = date("Y-m-d H:i:s");
         $inputdb['itemtype'] = "Computer";

         if ((isset($a_computerinventory['Computer']['domains_id']))
                    AND (!empty($a_computerinventory['Computer']['domains_id']))) {
               $inputdb['domain'] = $a_computerinventory['Computer']['domains_id'];
         }
         if (isset($a_computerinventory['Computer']['serial'])) {
            $inputdb['serial'] = $a_computerinventory['Computer']['serial'];
         }
         if (isset($a_computerinventory['Computer']['uuid'])) {
            $inputdb['uuid'] = $a_computerinventory['Computer']['uuid'];
         }
         if (isset($input['ip'])) {
            $inputdb['ip'] = $input['ip'];
         }
         if (isset($input['mac'])) {
            $inputdb['mac'] = $input['mac'];
         }

         $inputdb['entities_id'] = $input['entities_id'];

         if (isset($input['ip'])) {
            $inputdb['ip'] = exportArrayToDB($input['ip']);
         }
         if (isset($input['mac'])) {
            $inputdb['mac'] = exportArrayToDB($input['mac']);
         }
         $inputdb['rules_id'] = $data['_ruleid'];
         $inputdb['method'] = 'inventory';
         $inputdb['plugin_fusioninventory_agents_id'] = $_SESSION['plugin_fusioninventory_agents_id'];

         // if existing ignored device, update it
         if ($found = $pfIgnoredimportdevice->find(
               ['plugin_fusioninventory_agents_id' => $inputdb['plugin_fusioninventory_agents_id']],
               ['date DESC'], 1)) {
            $agent         = array_pop($found);
            $inputdb['id'] = $agent['id'];
            $pfIgnoredimportdevice->update($inputdb);
         } else {
            $pfIgnoredimportdevice->add($inputdb);
         }*/
      }
      /*global $DB;

      $rule = new \RuleImportComputerCollection();
      //$rule->getCollectionPart();

      $peripheral = new \Peripheral();
      $computer_Item = new \Computer_Item();

      $peripherals = [];
      $value = $this->data;

      foreach ($value as $key => $val) {
         $input = [
            'itemtype'     => 'Peripheral',
            'name'         => $val->name ?? '',
            'serial'       => $val->serial ?? '',
            'entities_id'  => $this->item->fields['entities_id']
         ];
         $data = $rule->processAllRules($input, [], ['class'=>$this, 'return' => true]);

         if (isset($data['found_inventories'])) {
            if ($data['found_inventories'][0] == 0) {
               // add peripheral
               $val->is_dynamic = 1;
               $val->entities_id = 0;//FIXME: $entities_id;
               $peripherals[] = $peripheral->add((array)$val);
            } else {
               $peripherals[] = $data['found_inventories'][0];
            }
         }
      }

      $db_peripherals = [];
      $iterator = $DB->request([
         'SELECT'    => [
            'glpi_peripherals.id',
            'glpi_computers_items.id AS link_id'
         ],
         'FROM'      => 'glpi_computers_items',
         'LEFT JOIN' => [
            'glpi_peripherals' => [
               'FKEY' => [
                  'glpi_peripherals'      => 'id',
                  'glpi_computers_items'  => 'items_id'
               ]
            ]
         ],
         'WHERE'     => [
            'itemtype'                          => 'Peripheral',
            'computers_id'                      => $this->item->fields['id'],
            'entities_id'                       => $this->item->fields['entities_id'],//FIXME: $entities_id,
            'glpi_computers_items.is_dynamic'   => 1,
            'glpi_peripherals.is_global'           => 0
         ]
      ]);

      while ($data = $iterator->next()) {
         $idtmp = $data['link_id'];
         unset($data['link_id']);
         $db_peripherals[$idtmp] = $data['id'];
      }

      if (count($db_peripherals) == 0) {
         foreach ($peripherals as $peripherals_id) {
            $input = [
               'computers_id'    => $this->item->fields['id'],
               'itemtype'        => 'Peripheral',
               'items_id'        => $peripherals_id,
               'is_dynamic'      => 1,
               //'_no_history'   => $no_history
            ];
            $computer_Item->add($input, [], !$no_history);
         }
      } else {
         // Check all fields from source:
         foreach ($peripherals as $key => $peripherals_id) {
            foreach ($db_peripherals as $keydb => $periphs_id) {
               if ($peripherals_id == $periphs_id) {
                  unset($peripherals[$key]);
                  unset($db_peripherals[$keydb]);
                  break;
               }
            }
         }

         if (count($peripherals) || count($db_peripherals)) {
            if (count($db_peripherals) != 0) {
               // Delete peripherals links in DB
               foreach ($db_peripherals as $idtmp => $data) {
                  $computer_Item->delete(['id'=>$idtmp], 1);
               }
            }
            if (count($peripherals) != 0) {
               foreach ($peripherals as $peripherals_id) {
                  $input = [
                     'computers_id'    => $this->item-fields['id'],
                     'itemtype'        => 'Peripheral',
                     'items_id'        => $peripherals_id,
                     'is_dynamic'      => 1,
                     //'_no_history'   => $no_history
                  ];
                  $computer_Item->add($input, [], !$no_history);
               }
            }
         }
      }*/
   }

   public function checkConf(Conf $conf) {
      return true;
   }

   /**
    * After rule engine passed, update task (log) and create item if required
    *
    * @global object $DB
    * @global string $PLUGIN_FUSIONINVENTORY_XML
    * @global boolean $PF_ESXINVENTORY
    * @global array $CFG_GLPI
    * @param integer $items_id id of the item (0 = not exist in database)
    * @param string $itemtype
    * @param integer $rules_id
    */
   public function rulepassed($items_id, $itemtype, $rules_id) {
      global $DB, $PLUGIN_FUSIONINVENTORY_XML, $PF_ESXINVENTORY, $CFG_GLPI;

      $no_history = false;
      $setdynamic = 1;

      $val = $this->data[0];
      $entities_id = 0; //FIXME: should not be hardcoded
      $val->entities_id = $entities_id;
      $_SESSION['glpiactiveentities']        = [$entities_id];
      $_SESSION['glpiactiveentities_string'] = $entities_id;
      $_SESSION['glpiactive_entity']         = $entities_id;

      //$entities_id = $_SESSION["plugin_fusioninventory_entity"];

      if ($items_id == 0) {
         /*$input = [];
         $input['entities_id'] = $entities_id;
         $input = PluginFusioninventoryToolbox::addDefaultStateIfNeeded('computer', $input);
         if (isset($input['states_id'])) {
               $a_computerinventory['Computer']['states_id'] = $input['states_id'];
         } else {
               $a_computerinventory['Computer']['states_id'] = 0;
         }*/

         $items_id = $this->item->add(\Toolbox::addslashes_deep((array)$val));
         $this->agent->update(['id' => $this->agent->fields['id'], 'items_id' => $items_id]);

         $no_history = true;
         $setdynamic = 0;
         //$_SESSION['glpi_fusionionventory_nolock'] = true;
      } else {
         $this->item->getFromDB($items_id);
      }

      $val->id = $this->item->fields['id'];

      /*PluginFusioninventoryToolbox::logIfExtradebug(
         "pluginFusioninventory-rules",
         "Rule passed : ".$items_id.", ".$itemtype."\n"
      );*/
      /*$pfFormatconvert = new \PluginFusioninventoryFormatconvert();*/

      //FIXME should be handle in a Computer asset
      //$this->data = $this->handleLinks($this->data, $itemtype, $items_id);
      /*$a_computerinventory = $pfFormatconvert->replaceids(
         $this->data,
         $itemtype,
         $items_id
      );*/

      if ($itemtype == 'Computer') {
         //$pfInventoryComputerLib = new PluginFusioninventoryInventoryComputerLib();
         //$pfAgent                = new PluginFusioninventoryAgent();

         if ($items_id == '0') {
            /*if ($entities_id == -1) {
               $entities_id = 0;
               $_SESSION["plugin_fusioninventory_entity"] = 0;
            }
            $_SESSION['glpiactiveentities']        = [$entities_id];
            $_SESSION['glpiactiveentities_string'] = $entities_id;
            $_SESSION['glpiactive_entity']         = $entities_id;*/
         } else {
            /*$$a_computerinventory['Computer']['states_id'] = $computer->fields['states_id'];
            $input = [];
            $input = PluginFusioninventoryToolbox::addDefaultStateIfNeeded('computer', $input);
            if (isset($input['states_id'])) {
                $a_computerinventory['Computer']['states_id'] = $input['states_id'];
            }

            if ($entities_id == -1) {
               $entities_id = $computer->fields['entities_id'];
               $_SESSION["plugin_fusioninventory_entity"] = $computer->fields['entities_id'];
            }

            $_SESSION['glpiactiveentities']        = [$entities_id];
            $_SESSION['glpiactiveentities_string'] = $entities_id;
            $_SESSION['glpiactive_entity']         = $entities_id;

            if ($computer->fields['entities_id'] != $entities_id) {
               $pfEntity = new PluginFusioninventoryEntity();
               $pfInventoryComputerComputer = new PluginFusioninventoryInventoryComputerComputer();
               $moveentity = false;
               if ($pfEntity->getValue('transfers_id_auto', $computer->fields['entities_id']) > 0) {
                  if (!$pfInventoryComputerComputer->getLock($items_id)) {
                     $moveentity = true;
                  }
               }
               if ($moveentity) {
                  $pfEntity = new PluginFusioninventoryEntity();
                  $transfer = new Transfer();
                  $transfer->getFromDB($pfEntity->getValue('transfers_id_auto', $entities_id));
                  $item_to_transfer = ["Computer" => [$items_id=>$items_id]];
                  $transfer->moveItems($item_to_transfer, $entities_id, $transfer->fields);
               } else {
                  $_SESSION["plugin_fusioninventory_entity"] = $computer->fields['entities_id'];
                  $_SESSION['glpiactiveentities']        = [$computer->fields['entities_id']];
                  $_SESSION['glpiactiveentities_string'] = $computer->fields['entities_id'];
                  $_SESSION['glpiactive_entity']         = $computer->fields['entities_id'];
                  $entities_id = $computer->fields['entities_id'];
               }
            }*/
         }
         /*if ($items_id > 0) {
            $a_computerinventory = $pfFormatconvert->extraCollectInfo(
                                                   $a_computerinventory,
                                                   $items_id);
         }
         $a_computerinventory = $pfFormatconvert->computerSoftwareTransformation(
                                                $a_computerinventory,
                                                $entities_id);
         */

         // * New

         /*if (isset($_SESSION['plugin_fusioninventory_locations_id'])) {
               $a_computerinventory['Computer']['locations_id'] =
                                 $_SESSION['plugin_fusioninventory_locations_id'];
               unset($_SESSION['plugin_fusioninventory_locations_id']);
         }*/

         /*$serialized = gzcompress(serialize($a_computerinventory));
         $a_computerinventory['fusioninventorycomputer']['serialized_inventory'] =
            Toolbox::addslashes_deep($serialized);*/

         /*if (!$PF_ESXINVENTORY) {
            $pfAgent->setAgentWithComputerid($items_id, $this->device_id, $entities_id);
         }*/

         /*$query = $DB->buildInsert(
            'glpi_plugin_fusioninventory_dblockinventories', [
               'value' => $items_id
            ]
         );
         $CFG_GLPI["use_log_in_files"] = false;
         if (!$DB->query($query)) {
            $communication = new PluginFusioninventoryCommunication();
            $communication->setMessage("<?xml version='1.0' encoding='UTF-8'?>
         <REPLY>
         <ERROR>ERROR: SAME COMPUTER IS CURRENTLY UPDATED</ERROR>
         </REPLY>");
            $communication->sendMessage($_SESSION['plugin_fusioninventory_compressmode']);
            exit;
         }
         $CFG_GLPI["use_log_in_files"] = true;*/

         // * For benchs
         //$start = microtime(TRUE);

         $this->item->update(\Toolbox::addslashes_deep((array)$val), !$no_history);
         /*PluginFusioninventoryInventoryComputerStat::increment();

         $pfInventoryComputerLib->updateComputer(
                 $a_computerinventory,
                 $items_id,
                 $no_history,
                 $setdynamic);*/

         /*$DB->delete(
            'glpi_plugin_fusioninventory_dblockinventories', [
               'value' => $items_id
            ]
         );
         if (isset($_SESSION['glpi_fusionionventory_nolock'])) {
            unset($_SESSION['glpi_fusionionventory_nolock']);
         }
          */

         // * For benchs
         //Toolbox::logInFile("exetime", (microtime(TRUE) - $start)." (".$items_id.")\n".
         //  memory_get_usage()."\n".
         //  memory_get_usage(TRUE)."\n".
         //  memory_get_peak_usage()."\n".
         //  memory_get_peak_usage()."\n");

         //FIXME: was conditionned like:
         /*if (isset($_SESSION['plugin_fusioninventory_rules_id'])) {
           //do the job
            unset($_SESSION['plugin_fusioninventory_rules_id']);
         }*/
         $rulesmatched = new \RuleMatchedLog();
         $inputrulelog = [
            'date'      => date('Y-m-d H:i:s'),
            'rules_id'  => $rules_id,
            'items_id'  => $items_id,
            'itemtype'  => $itemtype,
            'agents_id' => $this->agent->fields['id'],
            'method'    => 'inventory'

         ];
         /*if (isset($_SESSION['plugin_fusioninventory_agents_id'])) {
            $inputrulelog['plugin_fusioninventory_agents_id'] =
                           $_SESSION['plugin_fusioninventory_agents_id'];
         }*/
         $rulesmatched->add($inputrulelog, [], false);
         $rulesmatched->cleanOlddata($items_id, $itemtype);

         // Write inventory file
         $dir = GLPI_INVENTORY_DIR . '/' . $itemtype;
         if (!is_dir($dir)) {
            mkdir($dir);
         }
         file_put_contents($dir . '/'. $items_id . '.json', json_encode($this->raw_data, JSON_PRETTY_PRINT));
      } else if ($itemtype == 'PluginFusioninventoryUnmanaged') {

         /*$a_computerinventory = $pfFormatconvert->computerSoftwareTransformation(
                                                $a_computerinventory,
                                                $entities_id);

         $class = new $itemtype();
         if ($items_id == "0") {
            if ($entities_id == -1) {
               $_SESSION["plugin_fusioninventory_entity"] = 0;
            }
            $input = [];
            $input['date_mod'] = date("Y-m-d H:i:s");
            $items_id = $class->add($input);
            if (isset($_SESSION['plugin_fusioninventory_rules_id'])) {
               $pfRulematchedlog = new PluginFusioninventoryRulematchedlog();
               $inputrulelog = [];
               $inputrulelog['date'] = date('Y-m-d H:i:s');
               $inputrulelog['rules_id'] = $_SESSION['plugin_fusioninventory_rules_id'];
               if (isset($_SESSION['plugin_fusioninventory_agents_id'])) {
                  $inputrulelog['plugin_fusioninventory_agents_id'] =
                                 $_SESSION['plugin_fusioninventory_agents_id'];
               }
               $inputrulelog['items_id'] = $items_id;
               $inputrulelog['itemtype'] = $itemtype;
               $inputrulelog['method'] = 'inventory';
               $pfRulematchedlog->add($inputrulelog);
               $pfRulematchedlog->cleanOlddata($items_id, $itemtype);
               unset($_SESSION['plugin_fusioninventory_rules_id']);
            }
         }
         $class->getFromDB($items_id);
         $_SESSION["plugin_fusioninventory_entity"] = $class->fields['entities_id'];
         $input = [];
         $input['id'] = $class->fields['id'];

         // Write XML file
         if (!empty($PLUGIN_FUSIONINVENTORY_XML)) {
            PluginFusioninventoryToolbox::writeXML(
                    $items_id,
                    $PLUGIN_FUSIONINVENTORY_XML->asXML(),
                    'PluginFusioninventoryUnmanaged');
         }

         if (isset($a_computerinventory['Computer']['name'])) {
            $input['name'] = $a_computerinventory['Computer']['name'];
         }
         $input['item_type'] = "Computer";
         if (isset($a_computerinventory['Computer']['domains_id'])) {
            $input['domain'] = $a_computerinventory['Computer']['domains_id'];
         }
         if (isset($a_computerinventory['Computer']['serial'])) {
            $input['serial'] = $a_computerinventory['Computer']['serial'];
         }
         $class->update($input);*/
      }
   }

   /**
    * Get modified hardware
    *
    * @return stdClass
    */
   public function getHardware() {
      return $this->hardware;
   }
}
