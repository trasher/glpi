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

trait NetworkPort {
   protected $ports = [];

   public function handle() {
      parent::handle();
      $this->handlePorts();
   }

   /**
    * Get network ports
    *
    * @return array
    */
   public function getNetworkPorts() :array {
      return $this->ports;
   }

   /**
    * Manage network ports
    *
    * @param string $itemtype Item type, will take current item per default
    * @param integr $items_id Item ID, will take current item per default
    *
    * @return void
    */
   public function handlePorts($itemtype = null, $items_id = null) {
      global $DB;

      $networkports = $this->ports;
      $no_history = true;

      if ($items_id === null) {
         $items_id = $this->item->fields['id'];
      }
      if ($itemtype == null) {
         $itemtype = $this->item->getType();
      }

      $networkPort = new \NetworkPort();
      $networkName = new \NetworkName();
      $iPAddress   = new \IPAddress();
      $iPNetwork   = new \IPNetwork();
      $item_DeviceNetworkCard = new \Item_DeviceNetworkCard();

      foreach ($networkports as $a_networkport) {
         $a_networkport = (array)$a_networkport;
         if (isset($a_networkport['mac']) && $a_networkport['mac'] != '') {
            $a_networkports = $networkPort->find(
                  ['mac'      => $a_networkport['mac'],
                   'itemtype' => 'PluginFusioninventoryUnmanaged'],
                  [], 1);
            if (count($a_networkports) > 0) {
               $input = current($a_networkports);
               $unmanageds_id = $input['items_id'];
               $input['logical_number'] = $a_networkport['logical_number'];
               $input['itemtype'] = $itemtype;
               $input['items_id'] = $items_id;
               $input['is_dynamic'] = 1;
               $input['name'] = $a_networkport['name'];
               $networkPort->update($input, !$no_history);
               $pfUnmanaged = new PluginFusioninventoryUnmanaged();
               $pfUnmanaged->delete(['id'=>$unmanageds_id], 1);
            }
         }
      }
      // end get port from unknwon device

      $db_networkport = [];
      if ($no_history === false) {
         $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'mac', 'instantiation_type', 'logical_number'],
            'FROM'   => 'glpi_networkports',
            'WHERE'  => [
               'items_id'     => $items_id,
               'itemtype'     => $itemtype,
               'is_dynamic'   => 1
            ]
         ]);
         while ($data = $iterator->next()) {
            $idtmp = $data['id'];
            unset($data['id']);
            if (is_null($data['mac'])) {
               $data['mac'] = '';
            }
            if (preg_match("/[^a-zA-Z0-9 \-_\(\)]+/", $data['name'])) {
               $data['name'] = \Toolbox::addslashes_deep($data['name']);
            }
            $db_networkport[$idtmp] = array_map('strtolower', $data);
         }
      }
      $simplenetworkport = [];
      foreach ($networkports as $key => $a_networkport) {
         $a_networkport = (array)$a_networkport;
         // Add ipnetwork if not exist
         if (isset($a_networkport['gateway']) && $a_networkport['gateway'] != ''
                 && isset($a_networkport['netmask']) && $a_networkport['netmask'] != ''
                 && isset($a_networkport['subnet']) && $a_networkport['subnet']  != '') {
            if (countElementsInTable('glpi_ipnetworks',
                  [
                     'address'     => $a_networkport['subnet'],
                     'netmask'     => $a_networkport['netmask'],
                     'gateway'     => $a_networkport['gateway'],
                     'entities_id' => $this->entities_id,
                  ]) == 0) {

               $input_ipanetwork = [
                   'name'    => $a_networkport['subnet'].'/'.
                                $a_networkport['netmask'].' - '.
                                $a_networkport['gateway'],
                   'network' => $a_networkport['subnet'].' / '.
                                $a_networkport['netmask'],
                   'gateway' => $a_networkport['gateway'],
                   'entities_id' => $this->entities_id
               ];
               $iPNetwork->add($input_ipanetwork, [], !$no_history);
            }
         }

         // End add ipnetwork
         $a_field = ['name', 'mac', 'instantiation_type'];
         foreach ($a_field as $field) {
            if (isset($a_networkport[$field])) {
               $simplenetworkport[$key][$field] = $a_networkport[$field];
            }
         }
      }
      foreach ($simplenetworkport as $key => $arrays) {
         $arrayslower = array_map('strtolower', $arrays);
         foreach ($db_networkport as $keydb => $arraydb) {
            $logical_number = $arraydb['logical_number'];
            unset($arraydb['logical_number']);
            if ($arrayslower == $arraydb) {
               if ($networkports[$key]->logical_number != $logical_number) {
                  $input = [];
                  $input['id'] = $keydb;
                  $input['logical_number'] = $networkports[$key]->logical_number;
                  $networkPort->update($input, !$no_history);
               }

               // Add / update instantiation_type
               if (isset($networkports[$key]->instantiation_type)) {
                  $instantiation_type = $networkports[$key]->instantiation_type;
                  if (in_array($instantiation_type, ['NetworkPortEthernet',
                                                          'NetworkPortFiberchannel'])) {

                     $instance = new $instantiation_type;
                     $portsinstance = $instance->find(['networkports_id' => $keydb], [], 1);
                     if (count($portsinstance) == 1) {
                        $portinstance = current($portsinstance);
                        $input = $portinstance;
                     } else {
                        $input = [
                           'networkports_id' => $keydb
                        ];
                     }

                     if (isset($networkports[$key]->speed)) {
                        $input['speed'] = $networkports[$key]->speed;
                        $input['speed_other_value'] = $networkports[$key]->speed;
                     }
                     if (isset($networkports[$key]->wwn)) {
                        $input['wwn'] = $networkports[$key]->wwn;
                     }
                     if (isset($networkports[$key]->mac)) {
                        $networkcards = $item_DeviceNetworkCard->find(
                                ['mac'      => $networkports[$key]->mac,
                                 'itemtype' => $itemtype,
                                 'items_id' => $items_id],
                                [], 1);
                        if (count($networkcards) == 1) {
                           $networkcard = current($networkcards);
                           $input['items_devicenetworkcards_id'] = $networkcard['id'];
                        }
                     }
                     $input['_no_history'] = $no_history;
                     if (isset($input['id'])) {
                        $instance->update($input);
                     } else {
                        $instance->add($input);
                     }
                  }
               }

               // Get networkname
               $a_networknames_find = current($networkName->find(
                     ['items_id' => $keydb,
                      'itemtype' => 'NetworkPort'],
                     [], 1));
               if (!isset($a_networknames_find['id'])) {
                  $a_networkport['entities_id'] = $this->entities_id;
                  $a_networkport['items_id'] = $items_id;
                  $a_networkport['itemtype'] = $itemtype;
                  $a_networkport['is_dynamic'] = 1;
                  $a_networkport['_no_history'] = $no_history;
                  $a_networkport['items_id'] = $keydb;
                  unset($a_networkport['_no_history']);
                  $a_networkport['is_recursive'] = 0;
                  $a_networkport['itemtype'] = 'NetworkPort';
                  unset($a_networkport['name']);
                  $a_networkport['_no_history'] = $no_history;
                  $a_networknames_id = $networkName->add($a_networkport, [], !$no_history);
                  $a_networknames_find['id'] = $a_networknames_id;
               }

               // Same networkport, verify ipaddresses
               $db_addresses = [];
               $iterator = $DB->request([
                  'SELECT' => ['id', 'name'],
                  'FROM'   => 'glpi_ipaddresses',
                  'WHERE'  => [
                     'items_id'  => $a_networknames_find['id'],
                     'itemtype'  => 'NetworkName'
                  ]
               ]);
               while ($data = $iterator->next()) {
                  $db_addresses[$data['id']] = $data['name'];
               }
               $a_computerinventory_ipaddress = $networkports[$key]->ipaddress;
               $nb_ip = count($a_computerinventory_ipaddress);
               foreach ($a_computerinventory_ipaddress as $key2 => $arrays2) {
                  foreach ($db_addresses as $keydb2 => $arraydb2) {
                     if ($arrays2 == $arraydb2) {
                        unset($a_computerinventory_ipaddress[$key2]);
                        unset($db_addresses[$keydb2]);
                        break;
                     }
                  }
               }
               if (count($a_computerinventory_ipaddress) || count($db_addresses)) {
                  if (count($db_addresses) != 0 AND $nb_ip > 0) {
                     // Delete ip address in DB
                     foreach (array_keys($db_addresses) as $idtmp) {
                        $iPAddress->delete(['id'=>$idtmp], 1);
                     }
                  }
                  if (count($a_computerinventory_ipaddress) != 0) {
                     foreach ($a_computerinventory_ipaddress as $ip) {
                        $input = [];
                        $input['items_id']   = $a_networknames_find['id'];
                        $input['itemtype']   = 'NetworkName';
                        $input['name']       = $ip;
                        $input['is_dynamic'] = 1;
                        $iPAddress->add($input, [], !$no_history);
                     }
                  }
               }

               unset($db_networkport[$keydb]);
               unset($simplenetworkport[$key]);
               unset($networkports[$key]);
               break;
            }
         }
      }

      if (count($networkports) == 0
         AND count($db_networkport) == 0) {
         // Nothing to do
         $coding_std = true;
      } else {
         if (count($db_networkport) != 0) {
            // Delete networkport in DB
            foreach ($db_networkport as $idtmp => $data) {
               $networkPort->delete(['id'=>$idtmp], 1);
            }
         }
         if (count($networkports) != 0) {
            foreach ($networkports as $a_networkport) {
               $a_networkport = (array)$a_networkport;
               $a_networkport['entities_id'] = $this->entities_id;
               $a_networkport['items_id'] = $items_id;
               $a_networkport['itemtype'] = $itemtype;
               $a_networkport['is_dynamic'] = 1;
               $a_networkport['_no_history'] = $no_history;
               $a_networkport['items_id'] = $networkPort->add($a_networkport, [], !$no_history);
               unset($a_networkport['_no_history']);
               $a_networkport['is_recursive'] = 0;
               $a_networkport['itemtype'] = 'NetworkPort';
               unset($a_networkport['name']);
               $a_networkport['_no_history'] = $no_history;
               $a_networknames_id = $networkName->add($a_networkport, [], !$no_history);

               //\Toolbox::logWarning($a_networkport);
               foreach ($a_networkport['ipaddress'] as $ip) {
                  $input = [];
                  $input['items_id']   = $a_networknames_id;
                  $input['itemtype']   = 'NetworkName';
                  $input['name']       = $ip;
                  $input['is_dynamic'] = 1;
                  $input['_no_history'] = $no_history;
                  $iPAddress->add($input, [], !$no_history);
               }
               if (isset($a_networkport['instantiation_type'])) {
                  $instantiation_type = $a_networkport['instantiation_type'];
                  if (in_array($instantiation_type, ['NetworkPortEthernet',
                                                          'NetworkPortFiberchannel'])) {
                     $instance = new $instantiation_type;
                     $input = [
                        'networkports_id' => $a_networkport['items_id']
                     ];
                     if (isset($a_networkport['speed'])) {
                        $input['speed'] = $a_networkport['speed'];
                        $input['speed_other_value'] = $a_networkport['speed'];
                     }
                     if (isset($a_networkport['wwn'])) {
                        $input['wwn'] = $a_networkport['wwn'];
                     }
                     if (isset($a_networkport['mac'])) {
                        $networkcards = $item_DeviceNetworkCard->find(
                                ['mac'      => $a_networkport['mac'],
                                 'itemtype' => $itemtype,
                                 'items_id' => $items_id],
                                [], 1);
                        if (count($networkcards) == 1) {
                           $networkcard = current($networkcards);
                           $input['items_devicenetworkcards_id'] = $networkcard['id'];
                        }
                     }
                     $input['_no_history'] = $no_history;
                     $instance->add($input);
                  }
               }
            }
         }
      }
   }

   public function checkConf(Conf $conf) {
      return $conf->component_networkcard == 1;
   }
}
