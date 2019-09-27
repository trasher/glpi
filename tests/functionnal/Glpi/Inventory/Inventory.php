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

namespace tests\units\Glpi\Inventory;

use DbTestCase;

class Inventory extends DbTestCase {

   public function testImportComputer() {
      $json = file_get_contents(GLPI_ROOT . '/tests/fixtures/inventory/computer_1.json');

      $nbprinters = countElementsInTable(\Printer::getTable());
      $inventory = new \Glpi\Inventory\Inventory($json);

      if ($inventory->inError()) {
         foreach ($inventory->getErrors() as $error) {
            var_dump($error);
         }
      }
      $this->boolean($inventory->inError())->isFalse();
      $this->array($inventory->getErrors())->isEmpty();

      //check inventory metadata
      $metadata = $inventory->getMetadata();
      $this->array($metadata)->hasSize(5)
         ->string['deviceid']->isIdenticalTo('glpixps-2018-07-09-09-07-13')
         ->string['version']->isIdenticalTo('FusionInventory-Agent_v2.5.2-1.fc31')
         ->string['itemtype']->isIdenticalTo('Computer')
         ->string['tag']->isIdenticalTo('000005');
      $this->array($metadata['provider'])->hasSize(10);

      global $DB;
      //check created agent
      $agenttype = $DB->request(['FROM' => \AgentType::getTable(), 'WHERE' => ['name' => 'Core']])->next();
      $agents = $DB->request(['FROM' => \Agent::getTable()]);
      $this->integer(count($agents))->isIdenticalTo(1);
      $agent = $agents->next();
      $this->array($agent)
         ->string['deviceid']->isIdenticalTo('glpixps-2018-07-09-09-07-13')
         ->string['name']->isIdenticalTo('glpixps-2018-07-09-09-07-13')
         ->string['version']->isIdenticalTo('2.5.2-1.fc31')
         ->string['itemtype']->isIdenticalTo('Computer')
         ->integer['agenttypes_id']->isIdenticalTo($agenttype['id']);

      //get computer models, manufacturer, ...
      $autoupdatesystems = $DB->request(['FROM' => \AutoupdateSystem::getTable(), 'WHERE' => ['name' => 'GLPI Native Inventory']])->next();
      $this->array($autoupdatesystems);
      $autoupdatesystems_id = $autoupdatesystems['id'];

      $cmodels = $DB->request(['FROM' => \ComputerModel::getTable(), 'WHERE' => ['name' => 'XPS 13 9350']])->next();
      $this->array($cmodels);
      $computermodels_id = $cmodels['id'];

      $ctypes = $DB->request(['FROM' => \ComputerType::getTable(), 'WHERE' => ['name' => 'Laptop']])->next();
      $this->array($ctypes);
      $computertypes_id = $ctypes['id'];

      $cmanuf = $DB->request(['FROM' => \Manufacturer::getTable(), 'WHERE' => ['name' => 'Dell Inc.']])->next();
      $this->array($cmanuf);
      $manufacturers_id = $cmanuf['id'];

      //check created computer
      $computers_id = $agent['items_id'];
      $this->integer($computers_id)->isGreaterThan(0);
      $computer = new \Computer();
      $this->boolean($computer->getFromDB($computers_id))->isTrue();

      $expected = [
         'id' => $computers_id,
         'entities_id' => 0,
         'name' => 'glpixps',
         'serial' => '640HP72',
         'otherserial' => '000005',
         'contact' => 'trasher/root',
         'contact_num' => null,
         'users_id_tech' => 0,
         'groups_id_tech' => 0,
         'comment' => null,
         'date_mod' => $computer->fields['date_mod'],
         'autoupdatesystems_id' => $autoupdatesystems_id,
         'locations_id' => 0,
         'networks_id' => 0,
         'computermodels_id' => $computermodels_id,
         'computertypes_id' => $computertypes_id,
         'is_template' => 0,
         'template_name' => null,
         'manufacturers_id' => $manufacturers_id,
         'is_deleted' => 0,
         'is_dynamic' => 1,
         'users_id' => 0,
         'groups_id' => 0,
         'states_id' => 0,
         'ticket_tco' => '0.0000',
         'uuid' => '4c4c4544-0034-3010-8048-b6c04f503732',
         'date_creation' => $computer->fields['date_creation'],
         'is_recursive' => 0,
      ];
      $this->array($computer->fields)->isIdenticalTo($expected);

      //operating system
      $ios = new \Item_OperatingSystem();
      $iterator = $ios->getFromItem($computer);
      $record = $iterator->next();

      $expected = [
         'assocID' => $record['assocID'],
         'name' => 'Fedora',
         'version' => '31 (Workstation Edition)',
         'architecture' => 'x86_64',
         'servicepack' => null,
      ];
      $this->array($record)->isIdenticalTo($expected);

      //volumes
      $idisks = new \Item_Disk();
      $iterator = $idisks->getFromItem($computer);
      $this->integer(count($iterator))->isIdenticalTo(6);

      $expecteds = [
         [
            'fsname' => 'ext4',
            'name' => '/',
            'device' => '/dev/mapper/xps-root',
            'mountpoint' => '/',
            'filesystems_id' => 4,
            'totalsize' => 40189,
            'freesize' => 11683,
            'encryption_status' => 1,
            'encryption_tool' => 'LUKS1',
            'encryption_algorithm' => 'aes-xts-plain64',
            'encryption_type' => null,
         ], [
            'fsname' => 'ext4',
            'name' => '/var/www',
            'device' => '/dev/mapper/xps-www',
            'mountpoint' => '/var/www',
            'filesystems_id' => 4,
            'totalsize' => 20030,
            'freesize' => 11924,
            'encryption_status' => 0,
            'encryption_tool' => null,
            'encryption_algorithm' => null,
            'encryption_type' => null,
         ], [
            'fsname' => 'ext4',
            'name' => '/boot',
            'device' => '/dev/nvme0n1p2',
            'mountpoint' => '/boot',
            'filesystems_id' => 4,
            'totalsize' => 975,
            'freesize' => 703,
            'encryption_status' => 0,
            'encryption_tool' => null,
            'encryption_algorithm' => null,
            'encryption_type' => null,
         ], [
            'fsname' => 'ext4',
            'name' => '/var/lib/mysql',
            'device' => '/dev/mapper/xps-maria',
            'mountpoint' => '/var/lib/mysql',
            'filesystems_id' => 4,
            'totalsize' => 20030,
            'freesize' => 15740,
            'encryption_status' => 1,
            'encryption_tool' => 'LUKS1',
            'encryption_algorithm' => 'aes-xts-plain64',
            'encryption_type' => null,
         ], [
            'fsname' => 'ext4',
            'name' => '/home',
            'device' => '/dev/mapper/xps-home',
            'mountpoint' => '/home',
            'filesystems_id' => 4,
            'totalsize' => 120439,
            'freesize' => 24872,
            'encryption_status' => 1,
            'encryption_tool' => 'LUKS1',
            'encryption_algorithm' => 'aes-xts-plain64',
            'encryption_type' => null,
         ], [
            'fsname' => 'VFAT',
            'name' => '/boot/efi',
            'device' => '/dev/nvme0n1p1',
            'mountpoint' => '/boot/efi',
            'filesystems_id' => 7,
            'totalsize' => 199,
            'freesize' => 191,
            'encryption_status' => 0,
            'encryption_tool' => null,
            'encryption_algorithm' => null,
            'encryption_type' => null,
         ]
      ];

      $i = 0;
      while ($volume = $iterator->next()) {
         unset($volume['id']);
         unset($volume['date_mod']);
         unset($volume['date_creation']);
         $expected = $expecteds[$i];
         $expected = $expected + [
            'items_id'     => $computers_id,
            'itemtype'     => 'Computer',
            'entities_id'  => 0,
            'is_deleted'   => 0,
            'is_dynamic'   => 1
         ];
         $this->array($volume)->isEqualTo($expected);
         ++$i;
      }

      //connections
      $iterator = \Computer_Item::getTypeItems($computers_id, 'Monitor');
      $this->integer(count($iterator))->isIdenticalTo(1);
      $monitor_link = $iterator->next();
      unset($monitor_link['date_mod']);
      unset($monitor_link['date_creation']);

      $mmanuf = $DB->request(['FROM' => \Manufacturer::getTable(), 'WHERE' => ['name' => 'Sharp Corporation']])->next();
      $this->array($mmanuf);
      $manufacturers_id = $mmanuf['id'];

      $expected = [
         'id' => $monitor_link['id'],
         'entities_id' => 0,
         'name' => 'DJCP6',
         'contact' => 'trasher/root',
         'contact_num' => null,
         'users_id_tech' => 0,
         'groups_id_tech' => 0,
         'comment' => null,
         'serial' => '00000000',
         'otherserial' => null,
         'size' => '0.00',
         'have_micro' => 0,
         'have_speaker' => 0,
         'have_subd' => 0,
         'have_bnc' => 0,
         'have_dvi' => 0,
         'have_pivot' => 0,
         'have_hdmi' => 0,
         'have_displayport' => 0,
         'locations_id' => 0,
         'monitortypes_id' => 0,
         'monitormodels_id' => 0,
         'manufacturers_id' => $manufacturers_id,
         'is_global' => 0,
         'is_deleted' => 0,
         'is_template' => 0,
         'template_name' => null,
         'users_id' => 0,
         'groups_id' => 0,
         'states_id' => 0,
         'ticket_tco' => '0.0000',
         'is_dynamic' => 1,
         'is_recursive' => 0,
         'linkid' => $monitor_link['linkid'],
         'entity' => 0,
      ];
      $this->array($monitor_link)->isIdenticalTo($expected);

      $monitor = new \Monitor();
      $this->boolean($monitor->getFromDB($monitor_link['id']))->isTrue();
      $this->boolean((bool)$monitor->fields['is_dynamic'])->isTrue();
      $this->string($monitor->fields['name'])->isIdenticalTo('DJCP6');
      $this->string($monitor->fields['contact'])->isIdenticalTo('trasher/root');

      //check network ports
      $iterator = $DB->request([
         'FROM'   => \NetworkPort::getTable(),
         'WHERE'  => [
            'items_id'           => $computers_id,
            'itemtype'           => 'Computer',
         ],
      ]);
      $this->integer(count($iterator))->isIdenticalTo(5);

      $expecteds = [
         [
            'logical_number' => 0,
            'name' => 'lo',
            'instantiation_type' => 'NetworkPortEthernet',
            'mac' => '00:00:00:00:00:00',
         ], [
            'logical_number' => 1,
            'name' => 'enp57s0u1u4',
            'instantiation_type' => 'NetworkPortEthernet',
            'mac' => '00:e0:4c:68:01:db',
         ], [
            'logical_number' => 1,
            'name' => 'wlp58s0',
            'instantiation_type' => 'NetworkPortWifi',
            'mac' => '44:85:00:2b:90:bc',
         ], [
            'logical_number' => 0,
            'name' => 'virbr0',
            'instantiation_type' => 'NetworkPortEthernet',
            'mac' => '52:54:00:fa:20:0e',
         ], [
            'logical_number' => 0,
            'name' => 'virbr0-nic',
            'instantiation_type' => null,
            'mac' => '52:54:00:fa:20:0e',
         ]
      ];

      $ips = [
         'lo'  => [
            'v4'   => '127.0.0.1',
            'v6'   => '::1'
         ],
         'enp57s0u1u4'  => [
            'v4'   => '192.168.1.142',
            'v6'   => 'fe80::b283:4fa3:d3f2:96b1'
         ],
         'wlp58s0'   => [
            'v4'   => '192.168.1.118',
            'v6'   => 'fe80::92a4:26c6:99dd:2d60'
         ],
         'virbr0' => [
            'v4'   => '192.168.122.1'
         ]
      ];

      $i = 0;
      $netport = new \NetworkPort();
      while ($port = $iterator->next()) {
         $ports_id = $port['id'];
         $this->boolean($netport->getFromDB($ports_id))->isTrue();
         $instantiation = $netport->getInstantiation();
         if ($port['instantiation_type'] === null) {
            $this->boolean($instantiation)->isFalse();
         } else {
            $this->object($instantiation)->isInstanceOf($port['instantiation_type']);
         }

         unset($port['id']);
         unset($port['date_creation']);
         unset($port['date_mod']);
         unset($port['comment']);

         $expected = $expecteds[$i];
         $expected = $expected + [
            'items_id'     => $computers_id,
            'itemtype'     => 'Computer',
            'entities_id'  => 0,
            'is_recursive' => 0,
            'is_deleted'   => 0,
            'is_dynamic'   => 1
         ];

         $this->array($port)->isEqualTo($expected);
         ++$i;

         //check for ips
         /*$ip_iterator = $DB->request([
            'FROM'   => \IPAddress::getTable(),
            'INNER JOIN'   => [
               \NetworkName::getTable()   => [
                  'ON'  => [
                     \IPAddress::getTable()     => 'items_id',
                     \NetworkName::getTable()   => 'id', [
                        'AND' => [\IPAddress::getTable() . '.itemtype'  => \NetworkName::getType()]
                     ]
                  ]
               ]
            ],
            'WHERE'  => [
               \NetworkName::getTable() . '.itemtype'  => \NetworkPort::getType(),
               \NetworkName::getTable() . '.items_id'  => $ports_id
            ]
         ]);

         $this->boolean((bool)count($ip_iterator))->isIdenticalTo(isset($ips[$port['name']]));
         if (isset($ips[$port['name']])) {
            //FIXME: missing all ipv6 :(
            $ip = $ip_iterator->next();
            var_dump($ip);
            var_dump($ips[$port['name']]);
            var_dump($ips[$port['name']]['v4']);
            $this->integer((int)$ip['version'])->isIdenticalTo(4);
            $this->string($ip['name'])->isIdenticalTo($ips[$port['name']]['v4']);
         }*/
      }

      //check for components
      $components = [];
      $allcount = 0;
      foreach (\Item_Devices::getItemAffinities('Computer') as $link_type) {
         $link        = getItemForItemtype($link_type);
         $iterator = $DB->request($link->getTableGroupCriteria($computer));
         $allcount += count($iterator);
         $components[$link_type] = [];

         while ($row = $iterator->next()) {
            $lid = $row['id'];
            unset($row['id']);
            $components[$link_type][$lid] = $row;
         }
      }

      $expecteds = [
         'Item_DeviceMotherboard' => 0,
         'Item_DeviceFirmware' => 1,
         'Item_DeviceProcessor' => 1,
         'Item_DeviceMemory' => 2,
         'Item_DeviceHardDrive' => 1,
         'Item_DeviceNetworkCard' => 0,
         'Item_DeviceDrive' => 0,
         'Item_DeviceBattery' => 1,
         'Item_DeviceGraphicCard' => 0,
         'Item_DeviceSoundCard' => 1,
         'Item_DeviceControl' => 25,
         'Item_DevicePci' => 0,
         'Item_DeviceCase' => 0,
         'Item_DevicePowerSupply' => 0,
         'Item_DeviceGeneric' => 0,
         'Item_DeviceSimcard' => 0,
         'Item_DeviceSensor' => 0
      ];

      foreach ($expecteds as $type => $count) {
         $this->integer(count($components[$type]))->isIdenticalTo($count);
      }

      $expecteds = [
         'Item_DeviceMotherboard' => [],
         'Item_DeviceFirmware' => [
            [
               'items_id' => $computers_id,
               'itemtype' => 'Computer',
               'devicefirmwares_id' => 104,
               'is_deleted' => 0,
               'is_dynamic' => 1,
               'entities_id' => 0,
               'is_recursive' => 0,
               'serial' => null,
               'otherserial' => null,
               'locations_id' => 0,
               'states_id' => 0,
            ]
         ],
         'Item_DeviceProcessor' =>
               [
                  [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'deviceprocessors_id' => 3060400,
                  'frequency' => 2300,
                  'serial' => 'To Be Filled By O.E.M.',
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'nbcores' => 2,
                  'nbthreads' => 4,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
                  ],
            ],
            'Item_DeviceMemory' =>
               [
                  [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicememories_id' => 104,
                  'size' => 4096,
                  'serial' => '12161217',
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'busID' => '1',
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
                  ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicememories_id' => 104,
                  'size' => 4096,
                  'serial' => '12121212',
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'busID' => '2',
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
                  ],
            ],
            'Item_DeviceHardDrive' => [
               [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'deviceharddrives_id' => 104,
                  'capacity' => 256060,
                  'serial' => 'S29NNXAH146409',
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ],
            ],
            'Item_DeviceNetworkCard' => [],
            'Item_DeviceDrive' => [],
            'Item_DeviceBattery' => [
               [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicebatteries_id' => 104,
                  'manufacturing_date' => '2019-07-06',
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => '34605',
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ],
            ],
            'Item_DeviceGraphicCard' => [],
            'Item_DeviceSoundCard' => [
               [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicesoundcards_id' => 104,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ],
            ],
            'Item_DeviceControl' => [
               [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2246,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2247,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2248,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2249,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2250,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2251,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2252,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2253,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2254,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2255,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2256,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2257,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2258,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2259,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2260,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2261,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2262,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2263,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2263,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2263,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2263,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2264,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2265,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2266,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ], [
                  'items_id' => $computers_id,
                  'itemtype' => 'Computer',
                  'devicecontrols_id' => 2267,
                  'is_deleted' => 0,
                  'is_dynamic' => 1,
                  'entities_id' => 0,
                  'is_recursive' => 0,
                  'serial' => null,
                  'busID' => null,
                  'otherserial' => null,
                  'locations_id' => 0,
                  'states_id' => 0,
               ],
            ],
            'Item_DevicePci' => [],
            'Item_DeviceCase' => [],
            'Item_DevicePowerSupply' => [],
            'Item_DeviceGeneric' => [],
            'Item_DeviceSimcard' => [],
            'Item_DeviceSensor' => [],
      ];

      foreach ($expecteds as $type => $expected) {
         $component = array_values($components[$type]);
         //hack to replace expected fkeys
         foreach ($expected as $i => &$row) {
            foreach (array_keys($row) as $key) {
               if (isForeignKeyField($key)) {
                  $row[$key] = $component[$i][$key];
               }
            }
         }
         $this->array($component)->isIdenticalTo($expected);
      }

      //softwares
      $isoft = new \Item_SoftwareVersion();
      $iterator = $isoft->getFromItem($computer);
      $this->integer(count($iterator))->isIdenticalTo(6);

      $expecteds = [
         [
            'softname' => 'expat',
            'version' => '2.2.8-1.fc31',
            'dateinstall' => '2019-12-19',
         ], [
            'softname' => 'gettext',
            'version' => '0.20.1-3.fc31',
            'dateinstall' => '2020-01-15',
         ], [
            'softname' => 'gitg',
            'version' => '3.32.1-1.fc31',
            'dateinstall' => '2019-12-19',
         ], [
            'softname' => 'gnome-calculator',
            'version' => '3.34.1-1.fc31',
            'dateinstall' => '2019-12-19',
         ], [
            'softname' => 'libcryptui',
            'version' => '3.12.2-18.fc31',
            'dateinstall' => '2019-12-19',
         ], [
            'softname' => 'tar',
            'version' => '1.32-2.fc31',
            'dateinstall' => '2019-12-19',
         ],
      ];

      $i = 0;
      while ($soft = $iterator->next()) {
         $expected = $expecteds[$i];
         $this->array([
            'softname'     => $soft['softname'],
            'version'      => $soft['version'],
            'dateinstall'  => $soft['dateinstall']
         ])->isEqualTo($expected);
         ++$i;
      }

      //check printer
      $iterator = \Computer_Item::getTypeItems($computers_id, 'Printer');
      $this->integer(count($iterator))->isIdenticalTo(1);
      $printer_link = $iterator->next();
      unset($printer_link['date_mod']);
      unset($printer_link['date_creation']);

      $expected = [
         'id' => $printer_link['id'],
         'entities_id' => 0,
         'is_recursive' => 0,
         'name' => 'Officejet_Pro_8600_34AF9E_',
         'contact' => 'trasher/root',
         'contact_num' => null,
         'users_id_tech' => 0,
         'groups_id_tech' => 0,
         'serial' => 'MY47L1W1JHEB6',
         'otherserial' => null,
         'have_serial' => 0,
         'have_parallel' => 0,
         'have_usb' => 0,
         'have_wifi' => 0,
         'have_ethernet' => 0,
         'comment' => null,
         'memory_size' => null,
         'locations_id' => 0,
         'networks_id' => 0,
         'printertypes_id' => 0,
         'printermodels_id' => 0,
         'manufacturers_id' => 0,
         'is_global' => 0,
         'is_deleted' => 0,
         'is_template' => 0,
         'template_name' => null,
         'init_pages_counter' => 0,
         'last_pages_counter' => 0,
         'users_id' => 0,
         'groups_id' => 0,
         'states_id' => 0,
         'ticket_tco' => '0.0000',
         'is_dynamic' => 1,
         'linkid' => $printer_link['linkid'],
         'entity' => 0,
      ];
      $this->array($printer_link)->isIdenticalTo($expected);

      $printer = new \Printer();
      $this->boolean($printer->getFromDB($printer_link['id']))->isTrue();
      $this->boolean((bool)$printer->fields['is_dynamic'])->isTrue();
      $this->string($printer->fields['name'])->isIdenticalTo('Officejet_Pro_8600_34AF9E_');
      $this->string($printer->fields['contact'])->isIdenticalTo('trasher/root');

      $this->integer(countElementsInTable(\Printer::getTable()))->isIdenticalTo($nbprinters + 1);
   }
}
