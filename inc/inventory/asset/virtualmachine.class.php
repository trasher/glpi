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

class VirtualMachine extends InventoryAsset
{
   use NetworkPort;

   private $vms = [];

   public function prepare() :array {
      $mapping = [
         'memory'      => 'ram',
         'vmtype'      => 'virtualmachinetypes_id',
         'subsystem'   => 'virtualmachinesystems_id',
         'status'      => 'virtualmachinestates_id'
      ];

      $vm_mapping = [
         'memory'          => 'ram',
         'vmtype'          => 'computertypes_id',
         'operatingsystem' => 'operatingsystems_id',
         'customfields'    => 'comment'
      ];

      $net_mapping = [
         'description' => 'name',
         'macaddr'     => 'mac',
         'ipaddress'   => 'ip'
      ];

      if ($this->item->getType() != 'Computer') {
         throw new \RuntimeException('Virtual machines are handled for computers only.');
      }

      foreach ($this->data as $k => &$val) {
         $vm_val = clone($val);
         foreach ($mapping as $origin => $dest) {
            if (property_exists($val, $origin)) {
               $val->$dest = $val->$origin;
            }
         }

         // Hack for BSD jails
         if (property_exists($val, 'virtualmachinetypes_id') && $val->virtualmachinetypes_id == 'jail') {
            //FIXME: get item UUID?
            $val->uuid = "-" . $val->name;
         }

         foreach ($vm_mapping as $origin => $dest) {
            if (property_exists($vm_val, $origin)) {
               $vm_val->$dest = $vm_val->$origin;
            }
         }

         if (property_exists($vm_val, 'memory')) {
            if (strstr($vm_val->memory, 'MB')) {
               $vm_val = str_replace('MB', '', $vm_val->memory);
            } else if (strstr($vm_val->memory, 'KB')) {
               $vm_val = str_replace('KB', '', $vm_val->memory) / 1000;
            } else if (strstr($vm_val->memory, 'GB')) {
               $vm_val->memory = str_replace('GB', '', $vm_val->memory) * 1000;
            } else if (strstr($vm_val->memory, 'B')) {
               $vm_val->memory = str_replace('B', '', $vm_val->memory) / 1000000;
            }
         }

         if (property_exists($vm_val, 'comment') && is_array($vm_val->comment)) {
            $comments = '';
            foreach ($vm_val->comment as $comment) {
               $comments .= $comment->name . ' : ' . $comment->value;
            }
            $vm_val->comment = $comments;
         }
         $this->vms[] = $vm_val;

         if (property_exists($vm_val, 'networks') && is_array($vm_val->networks)) {
            foreach ($vm_val->networks as $net_key => $net_value) {
               foreach ($net_mapping as $origin => $dest) {
                  if (property_exists($net_val, $origin)) {
                     $net_val->$dest = $net_val->$origin;
                  }
               }

               if (property_exists($net_val, 'name') && property_exists($net_val, 'mac')) {
                  $net_val->instantiation_type = 'NetworkPortEthernet';
                  if (property_exists($net_val, 'mac')) {
                     $net_val->mac = strtolower($net_val->mac);
                  }
                  if (isset($this->ports[$net_val->name . '-' . $net_val->mac])) {
                     if (property_exists($net_val, 'ip')) {
                        $this->ports[$net_val->name . '-' . $net_val->mac]['ipaddress'][] = $net_val->ip;
                     }
                  } else {
                     if (property_exists($net_val, 'ip') && $net_val->ip != '') {
                        if (property_exists($net_val, 'ip')) {
                           $net_val->ip = [$net_val->ip];
                        }
                     }
                     $this->ports[$net_val->name . '-' . $net_val->mac]['ipaddress'][] = $net_val->ip;
                  }
               }
            }
         }
      }

      return $this->data;
   }

   public function handle() {
      global $DB;

      $value = $this->data;
      $computerVirtualmachine = new \ComputerVirtualMachine();

      $db_vms = [];
      //if ($no_history === false) {
         $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'uuid', 'virtualmachinesystems_id'],
            'FROM'   => 'glpi_computervirtualmachines',
            'WHERE'  => [
               'computers_id' => $this->item->fields['id'],
               'is_dynamic'   => 1
            ]
         ]);
         while ($row = $iterator->next()) {
            $idtmp = $row['id'];
            unset($row['id']);
            $db_vms[$idtmp] = $row;
         }
      //}

      if (count($db_vms) == 0) {
         foreach ($value as $val) {
            $input = (array)$val;
            $input['computers_id'] = $this->item->fields['id'];
            $input['is_dynamic']  = 1;
            $computerVirtualmachine->add($input, []/*, !$no_history*/);
         }
      } else {
         foreach ($db_vms as $keydb => $arraydb) {
            foreach ($value as $key => $val) {
               $sinput = [
                  'name'                     => $val->name ?? '',
                  'uuid'                     => $val->uuid ?? '',
                  'virtualmachinesystems_id' => $val->virtualmachinesystems_id ?? ''
               ];
               if ($sinput == $arraydb) {
                  $input = [
                     'id'           => $keydb,
                     'is_dynamic'   => 1
                  ];

                  foreach (['vcpu', 'memory', 'virtualmachinetypes_id', 'virtualmachinestates_id'] as $prop) {
                     if (property_exists($val, $prop)) {
                        $input[$prop] = $val->$prop;
                     }
                  }
                  $computerVirtualmachine->update($input/*, !$no_history*/);
                  unset($value[$key]);
                  unset($db_vms[$keydb]);
                  break;
               }
            }
         }

         // Check all fields from source:
         if (count($db_vms) != 0) {
            // Delete virtual machines links in DB
            foreach ($db_vms as $idtmp => $data) {
               $computerVirtualmachine->delete(['id' => $idtmp], 1);
            }
         }
         if (count($value) != 0) {
            foreach ($value as $val) {
               $input = (array)$val;
               $input['computers_id'] = $this->item->fields['id'];
               $computerVirtualmachine->add($input, []/*, !$no_history*/);
            }
         }
      }

      //if ($pfConfig->getValue("create_vm") == 1) {
         // Create VM based on information of section VIRTUALMACHINE
         //$pfAgent = new PluginFusioninventoryAgent();

         // Use ComputerVirtualMachine::getUUIDRestrictRequest to get existant
         // vm in computer list
         $computervm = new \Computer();
         foreach ($this->vms as $vm) {
            // Define location of physical computer (host)
            $vm->locations_id = $this->item->fields['locations_id'];

            if (property_exists($vm, 'uuid') && $vm->uuid != '') {
               $iterator = $DB->request([
                  'SELECT' => 'id',
                  'FROM'   => 'glpi_computers',
                  'WHERE'  => [
                     'RAW' => [
                        'LOWER(uuid)'  => \ComputerVirtualMachine::getUUIDRestrictCriteria($vm->uuid)
                     ]
                  ],
                  'LIMIT'  => 1
               ]);
               $computers_vm_id = 0;
               while ($data = $iterator->next()) {
                  $computers_vm_id = $data['id'];
               }
               if ($computers_vm_id == 0) {
                  //if ($pfAgent->getAgentWithComputerid($computers_vm_id) === false) {
                  //TODO: well, is that possible?
               }
               if ($computers_vm_id == 0) {
                  // Add computer
                  $vm->entities_id = $this->item->fields['entities_id'];
                  $computers_vm_id = $computervm->add((array)$vm, []/*, !$no_history*/);
                  // Manage networks
                  //$this->manageNetworkPort($a_vm['networkport'], $computers_vm_id, false);
               } else {
                  //if ($pfAgent->getAgentWithComputerid($computers_vm_id) === false) {
                     // Update computer
                     $input = (array)$vm;
                     $input['id'] = $computers_vm_id;
                     $computervm->update($input/*, !$no_history*/);
                     // Manage networks
                     //$this->manageNetworkPort($a_vm['networkport'], $computers_vm_id, false);
                  //}
               }
               // Manage networks
               $this->handlePorts('Computer', $computers_vm_id);
            }
         }
      //}
   }

   public function checkConf(Conf $conf) {
      return $conf->import_vm == 1;
   }
}
