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

namespace Glpi\Inventory;

/**
 * Handle inventory request
 */
class Inventory
{
    const FULL_MODE = 0;
    const INCR_MODE = 1;

    /** @var integer */
   private $mode;
    /** @var stdClass */
   private $raw_data = null;
   /** @var array */
   private $data = [];
    /** @var array */
   private $metadata;
    /** @var array */
   private $errors = [];
   /** @var CommonDBTM */
   private $item;
   /** @var array */
   private $devicetypes;

    /**
     * @param mixed   $data   Inventory data, optionnal
     * @param integer $mode   One of self::*_MODE
     * @param integer $format One of Request::*_MODE
     */
   public function __construct($data = null, $mode = self::FULL_MODE, $format = Request::JSON_MODE) {
       $this->mode = $mode;

      if (null !== $data) {
          $this->setData($data, $format);
          $this->extractMetadata();
          $this->doInventory();
      }
   }

    /**
     * Set data, and convert them if we're using legacy format
     *
     * @param mixed   $data   Inventory data, optionnal
     * @param integer $format One of self::*_FORMAT
     *
     * @return boolean
     */
   public function setData($data, $format = Request::JSON_MODE) :bool {
       $converter = new Converter;
      if (Request::XML_MODE === $format) {
          //convert legacy format
          $data = $converter->convert($data->asXML());
      }

      try {
          $converter->validate($data);
      } catch (\RuntimeException $e) {
          $this->errors[] = $e->getMessage();
          \Toolbox::logError($e->printStackTrace());
          return false;
      }

       $this->raw_data = json_decode($data);
       file_put_contents(GLPI_TMP_DIR . '/local_inv.json', $data);
       return true;
   }

    /**
     * Prepare inventory data
     *
     * @return array
     */
   public function extractMetadata() :array {
       //check
      if ($this->inError()) {
          throw new \RuntimeException('Previous error(s) exists!');
      }

       $this->metadata = [
           'deviceid'  => $this->raw_data->deviceid,
           'version'   => $this->raw_data->content->versionclient
       ];

       return $this->metadata;
   }

    /**
     * Do inventory
     *
     * @return array
     */
   public function doInventory() {
      global $DB;

      //check
      if ($this->inError()) {
         throw new \RuntimeException('Previous error(s) exists!');
      }

      try {
         $DB->beginTransaction();

         $converter = new Converter;
         $schema = json_decode(file_get_contents($converter->getSchemaPath()), true);

         $properties = array_keys($schema['properties']['content']['properties']);
         unset($properties['versionclient']); //already handled in extractMetadata
         $contents = $this->raw_data->content;

         $data = [];
         //parse schema properties and handle if it exists in raw_data
         foreach ($properties as $property) {
            if (property_exists($contents, $property)) {
               $this->metadata['provider'] = [];

               $sub_properties = [];
               if (isset($schema['properties']['content']['properties'][$property]['properties'])) {
                  $sub_properties = array_keys($schema['properties']['content']['properties'][$property]['properties']);
               }

               switch ($property) {
                  case 'versionprovider':
                     foreach ($sub_properties as $sub_property) {
                        if (property_exists($contents->$property, $sub_property)) {
                           $this->metadata['provider'][$sub_property] = $contents->$property->$sub_property;
                        }
                     }
                     break;
                  default:
                     if (count($sub_properties)) {
                        $data[$property] = [];
                        foreach ($sub_properties as $sub_property) {
                           if (property_exists($contents->$property, $sub_property)) {
                              $data[$property][$sub_property] = $contents->$property->$sub_property;
                           }
                        }
                     } else {
                        $data[$property] = $contents->$property;
                     }
                     break;
               }
            }
         }

         $this->data = $data;
         //create/load agent
         $agent = new \Agent();
         $agent->handleAgent($this->metadata);

         $this->item = new $agent->fields['itemtype'];
         if (empty($agent->fields['items_id'])) {
            $iid = $this->item->add([
               'name'         => $this->metadata['deviceid'],
               'entities_id'  => 0,
               'is_dynamic'   => 1
            ]);
            $agent->update(['id' => $agent->fields['id'], 'items_id' => $iid]);
         } else {
            $this->item->getFromDB($agent->fields['items_id']);
         }

         $this->processInventoryData();
         $this->handleDevices();

         $this->errors[] = 'Inventory is not yet implemented';

         $DB->commit();
      } catch (\Exception $e) {
         \Toolbox::logError($e);
         $DB->rollback();
         throw $e;
      }

      return [];
   }

    /**
     * Get error
     *
     * @return array
     */
   public function getErrors() :array {
       return $this->errors;
   }

    /**
     * Check if erorrs has been throwed
     *
     * @return boolean
     */
   public function inError() :bool {
       return (bool)count($this->errors);
   }

   static function getMenuContent() {

      $menu = [
         'title'  => __('Inventory'),
         'page'   => '/front/inventory.conf.php',
         'options'   => [
            'agent' => [
               'title' => \Agent::getTypeName(\Session::getPluralNumber()),
               'page'  => \Agent::getSearchURL(false),
               'links' => [
                  'add'    => '/front/agent.form.php',
                  'search' => '/front/agent.php',
               ]
            ]
         ]
      ];

      if (count($menu)) {
         return $menu;
      }
      return false;
   }

   /**
    * Process and enhance data
    *
    * @return void
    */
   public function processInventoryData() {
      global $DB;

      $hdd = [];
      $ports = [];

      foreach ($this->data as $key => &$value) {
         $itemdevicetype = false;
         switch ($key) {
            case 'accesslog':
            case 'accountinfo':
               break;
            case 'cpus':
               $itemdevicetype = 'Item_DeviceProcessor';
               $mapping = [
                  'speed'        => 'frequency',
                  'manufacturer' => 'manufacturers_id',
                  'serial'       => 'serial',
                  'name'         => 'designation',
                  'core'         => 'nbcores',
                  'thread'       => 'nbthreads'
               ];
               foreach ($value as &$val) {
                  foreach ($mapping as $origin => $dest) {
                     if (property_exists($val, $origin)) {
                        $val->$dest = $val->$origin;
                     }
                  }
                  if (property_exists($val, 'frequency')) {
                     $val->frequency_default = $val->frequency;
                     $val->frequence = $val->frequency;
                  }
                  if (property_exists($val, 'type')) {
                     $val->designation = $val->type;
                  }
               }
               break;
            case 'drives':
            case 'envs':
            case 'firewalls':
               break;
            case 'hardware':
               $mapping = [
                  'NAME'           => 'name',
                  'WINPRODID'      => 'licenseid',
                  'WINPRODKEY'     => 'license_number',
                  'WORKGROUP'      => 'domains_id',
                  'UUID'           => 'uuid',
                  'LASTLOGGEDUSER' => 'users_id',
               ];

               $val = (object)$value;
               foreach ($mapping as $origin => $dest) {
                  if (property_exists($val, $origin)) {
                     $val->$dest = $val->$origin;
                  }
               }
               break;
            case 'inputs':
            case 'local_groups':
            case 'local_users':
            case 'physical_volumes':
            case 'volume_groups':
            case 'logical_volumes':
               break;
            case 'memories':
               $itemdevicetype = 'Item_DeviceMemory';
               $mapping = [
                  'capacity'     => 'size',
                  'speed'        => 'frequence',
                  'type'         => 'devicememorytypes_id',
                  'serialnumber' => 'serial',
                  'numslots'     => 'busID'
               ];

               foreach ($value as $k => &$val) {
                  if ($val->capacity > 0) {
                     foreach ($mapping as $origin => $dest) {
                        if (property_exists($val, $origin)) {
                           $val->$dest = $val->$origin;
                        }
                     }
                  } else {
                     unset($value[$k]);
                     continue;
                  }

                  $designation = '';
                  if (property_exists($val, 'type')
                     && $val->type != 'Empty Slot'
                     && $val->type != 'Unknown'
                  ) {
                     $designation = $val->type;
                  }
                  if (property_exists($val, 'description')) {
                     if ($designation != '') {
                        $designation .= ' - ';
                     }
                     $designation .= $val->description;
                  }

                  if ($designation != '') {
                     $val->designation = $designation;
                  }

                  if (property_exists($val, 'frequence')) {
                     $val->frequence = str_replace([' MHz', ' MT/s'], '', $val->frequence);
                  }
               }
               break;
            case 'monitors':
               break;
            case 'networks':
               $itemdevicetype = 'Item_DeviceNetworkCard';
               $mapping = [
                  'name'          => 'designation',
                  'manufacturer'  => 'manufacturers_id',
                  'macaddr'       => 'mac'
               ];
               $mapping_ports = [
                  'description' => 'name',
                  'macaddr'     => 'mac',
                  'type'        => 'instantiation_type',
                  'ipaddress'   => 'ip',
                  'virtualdev'  => 'virtualdev',
                  'ipsubnet'    => 'subnet',
                  'ssid'        => 'ssid',
                  'ipgateway'   => 'gateway',
                  'ipmask'      => 'netmask',
                  'ipdhcp'      => 'dhcpserver',
                  'wwn'         => 'wwn',
                  'speed'       => 'speed'
               ];

               foreach ($value as $k => &$val) {
                  if (!property_exists($val, 'description')) {
                     unset($value[$k]);
                  } else {
                     foreach ($mapping as $origin => $dest) {
                        if (property_exists($val, $origin)) {
                           $val->$dest = $val->$origin;
                        }
                     }

                     if (isset($this->data['controllers'])) {
                        $found_controller = false;
                        foreach ($this->data['controllers'] as $controller) {
                           if (property_exists($controller, 'type')
                              && ($val->description == $controller->type
                                 || strtolower($val->description." controller") ==
                                          strtolower($controller->type))
                                 /*&& !isset($ignorecontrollers[$a_controllers['NAME']])*/) {
                              $found_controller = $controller;
                              if (property_exists($val, 'macaddr')) {
                                 $found_controller->macaddr = $val->macaddr;
                                 break; //found, exit loop
                              }
                           }
                        }

                        if ($found_controller) {
                           /*if (isset($a_found['PCIID'])) {
                              $a_PCIData =
                                    PluginFusioninventoryInventoryExternalDB::getDataFromPCIID(
                                    $a_found['PCIID']
                                    );
                              if (isset($a_PCIData['manufacturer'])) {
                                 $array_tmp['manufacturers_id'] = $a_PCIData['manufacturer'];
                              }
                              if (isset($a_PCIData['name'])) {
                                 $array_tmp['designation'] = $a_PCIData['name'];
                              }
                              $array_tmp['designation'] = Toolbox::addslashes_deep($array_tmp['designation']);
                           }*/
                           if (property_exists($val, 'mac')) {
                              $val->mac = strtolower($val->mac);
                           }
                        }
                     }
                  }

                  //network ports
                  $val_port = clone $val;
                  $ports = [];
                  foreach ($mapping_ports as $origin => $dest) {
                     if (property_exists($val_port, $origin)) {
                        $val_port->$dest = $val_port->$origin;
                     }
                  }

                  if (property_exists($val_port, 'name')
                     && $val_port->name != ''
                     || property_exists($val_port, 'mac')
                     && $val_port->mac != ''
                  ) {
                     $val_port->logical_number = 1;
                     if (property_exists($val_port, 'virtualdev')) {
                        if ($val_port->virtualdev != 1) {
                           $val_port->virtualdev = 0;
                        } else {
                           $val_port->logical_number = 0;
                        }
                     }
                     $val_port->mac = strtolower($val_port->mac);
                     $portkey = $val_port->name . '-' . $val_port->mac;

                     if (isset($ports[$portkey])) {
                        if (property_exists($val_port, 'ip') && $val_port->ip != '') {
                           if (!in_array($val_port->ip, $ports[$portkey]->ipaddress)) {
                              $ports[$portkey]->ipaddress[] = $val_port->ip;
                           }
                        }
                        if (property_exists($val_port, 'ipaddress6') && $val_port->ipaddress6 != '') {
                           if (!in_array($val_port->ipaddress6, $ports[$portkey]->ipaddress)) {
                              $ports[$portkey]->ipaddress[] = $val_port->ipaddress6;
                           }
                        }
                     } else {
                        if (property_exists($val_port, 'ip')) {
                           if ($val_port->ip != '') {
                              $val_port->ipaddress = [$val_port->ip];
                           }
                           unset($val_port->ip);
                        } else if (property_exists($val_port, 'ipaddress6') && $val_port->ipaddress6 != '') {
                           $val_port->ipaddress = [$val_port->ipaddress6];
                        } else {
                           $val_port->ipaddress = [];
                        }

                        if (property_exists($val_port, 'instantiation_type')) {
                           switch ($val_port->instantiation_type) {
                              case 'Ethernet':
                                 $val_port->instantiation_type = 'NetworkPortEthernet';
                                 break;
                              case 'wifi':
                                 $val_port->instantiation_type = 'NetworkPortWifi';
                                 break;
                              case 'fibrechannel':
                              case 'fiberchannel':
                                 $val_port->instantiation_type = 'NetworkPortFiberchannel';
                                 break;
                              default:
                                 if (property_exists($val_port, 'wwn') && !empty($val_port->wwn)) {
                                    $val_port->instantiation_type = 'NetworkPortFiberchannel';
                                 } else if ($val_port->mac != '') {
                                    $val_port->instantiation_type = 'NetworkPortEthernet';
                                 } else {
                                    $val_port->instantiation_type = 'NetworkPortLocal';
                                 }
                                 break;
                           }
                        }

                        // Test if the provided network speed is an integer number
                        if (property_exists($val_port, 'speed')
                           && ctype_digit (strval($val_port->speed))
                        ) {
                           // Old agent version have speed in b/s instead Mb/s
                           if ($val_port->speed > 100000) {
                              $val_port->speed = $val_port->speed / 1000000;
                           }
                        } else {
                           $val_port->speed = 0;
                        }

                        $uniq = '';
                        if (!empty($val_port->mac)) {
                           $uniq = $val_port->mac;
                        } else if (property_exists($val_port, 'wwn') && !empty($val_port->wwn)) {
                           $uniq = $val_port->wwn;
                        }
                        $ports[$val_port->name.'-'.$uniq] = $val_port;
                     }
                  }
               }
               $suffix = '__ports';
               $this->data[$key . $suffix] = $ports;
               break;
            case 'operatingsystem':
            case 'ports':
            case 'printers':
            case 'processes':
            case 'remote_mgmt':
            case 'slots':
            case 'softwares':
               break;
            case 'sounds':
               $itemdevicetype = 'Item_DeviceSoundCard';
               $mapping = [
                  'name'          => 'designation',
                  'manufacturer'  => 'manufacturers_id',
                  'description'   => 'comment'
               ];
               foreach ($value as $k => &$val) {
                  foreach ($mapping as $origin => $dest) {
                     if (property_exists($val, $origin)) {
                        $val->$dest = $val->$origin;
                     }
                  }
               }
               break;
            case 'storages':
               $itemdevicetype = 'Item_DeviceDrive';

               $mapping_drive = [
                  'serialnumber' => 'serial',
                  'name'         => 'designation',
                  'type'         => 'interfacetypes_id',
                  'manufacturer' => 'manufacturers_id',
               ];
               $mapping = [
                  'disksize'      => 'capacity',
                  'interface'     => 'interfacetypes_id',
                  'manufacturer'  => 'manufacturers_id',
                  'model'         => 'designation',
                  'serialnumber'  => 'serial'
               ];

               $hdd = [];
               foreach ($value as $k => &$val) {
                  if ($this->isDrive($val)) { // it's cd-rom / dvd
                     foreach ($mapping_drive as $origin => $dest) {
                        if (property_exists($val, $origin)) {
                           $val->$dest = $val->$origin;
                        }
                     }

                     if (property_exists($val, 'description')) {
                        $val->designation = $val->description;
                     }
                  } else { // it's harddisk
                     foreach ($mapping as $origin => $dest) {
                        if (property_exists($val, $origin)) {
                           $val->$dest = $val->$origin;
                        }
                     }

                     if ((!property_exists($val, 'model') || $val->model == '') && property_exists($val, 'name')) {
                        $val->designation = $val->name;
                     }

                     $hdd[] = $val;
                     unset($value[$k]);
                  }
               }
               $suffix = 'hdd';
               $this->data[$key . $suffix] = $hdd;
               $altitemdevicetype = 'Item_DeviceHardDrive';
               break;
            case 'usbdevices':
               break;
            case 'antivirus':
               break;
            case 'bios':
               $itemdevicetype = 'Item_DeviceFirmware';
               $mapping = [
                  'bdate'           => 'date',
                  'bversion'        => 'version',
                  'bmanufacturer'   => 'manufacturers_id',
                  'biosserial'      => 'serial'
               ];

               $val = (object)$value;

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

               $value = [$val];
               break;
            case 'batteries':
               $itemdevicetype = 'Item_DeviceBattery';
               $this->devices[$itemdevicetype] = $key;
               $mapping = [
                  'name'         => 'designation',
                  'manufacturer' => 'manufacturers_id',
                  'serial'       => 'serial',
                  'date'         => 'manufacturing_date',
                  'capacity'     => 'capacity',
                  'chemistry'    => 'devicebatterytypes_id',
                  'voltage'      => 'voltage'
               ];

               foreach ($value as $k => &$val) {
                  foreach ($mapping as $origin => $dest) {
                     if (property_exists($val, $origin)) {
                        $val->$dest = $val->$origin;
                     }
                  }

                  if (!isset($val->voltage) || $val->voltage == '') {
                     //a numeric value is expected here
                     $val->voltage = 0;
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

                     // test date_install
                     $matches = [];
                     if (property_exists($val, 'manufacturing_date')) {
                        preg_match("/^(\d{2})\/(\d{2})\/(\d{4})$/", $val->manufacturing_date, $matches);
                        if (count($matches) == 4) {
                           $val->manufacturing_date = $matches[3]."-".$matches[2]."-".$matches[1];
                        } else {
                           unset($val->manufacturing_date);
                        }
                     }
                  }
               }

               break;
            case 'controllers':
               $itemdevicetype = 'Item_DeviceControl';
               $this->devices[$itemdevicetype] = $key;
               $mapping = [
                  'name'          => 'designation',
                  'manufacturer'  => 'manufacturers_id',
                  'type'          => 'interfacetypes_id'
               ];
               foreach ($value as $k => &$val) {
                  if (property_exists($val, 'name')) {
                     foreach ($mapping as $origin => $dest) {
                        if (property_exists($val, $origin)) {
                           $val->$dest = $val->$origin;
                        }

                        /*if (property_exists($val, 'pciid')) {
                           $a_PCIData =
                                 /** FIXME: Whaaaaat?! oO
                                 PluginFusioninventoryInventoryExternalDB::getDataFromPCIID(
                                    $a_controllers['PCIID']
                                 );
                           if (isset($a_PCIData['manufacturer'])) {
                              $array_tmp['manufacturers_id'] = $a_PCIData['manufacturer'];
                           }
                           if (isset($a_PCIData['name'])) {
                              $array_tmp['designation'] = $a_PCIData['name'];
                           }
                           $array_tmp['designation'] = Toolbox::addslashes_deep($array_tmp['designation']);
                        }*/
                     }
                  } else {
                     unset($value[$k]);
                  }
               }
               break;
            case 'videos':
               $itemdevicetype = 'Item_DeviceGraphicCard';
               $mapping = [
                  'name'   => 'designation',
                  'memory' => 'memory'
               ];

               foreach ($value as $k => &$val) {
                  if (property_exists($val, 'name')) {
                     foreach ($mapping as $origin => $dest) {
                        if (property_exists($val, $origin)) {
                           $val->$dest = $val->$origin;
                        }
                     }
                  } else {
                     unset($value[$k]);
                  }
               }
            case 'users':
            case 'versionclient':
            case 'versionprovider':
               break;
            case 'simcards':
               $itemdevicetype = 'Item_DeviceSimcard';
               //no mapping needed
               break;
            default:
               //unhandled
               throw new \RuntimeException("Unhandled schema entry $key");
               break;
         }

         //create mapping for existing devices
         if ($itemdevicetype !== false) {
            $this->devices[$itemdevicetype] = $key;
            if (isset($suffix)) {
               $this->devices[$altitemdevicetype] = $key . $suffix;
            }
         }
      }
   }

   /**
    * Handle devices
    *
    * @return void
    */
   public function handleDevices() {
      global $DB;

      $this->devicetypes = \Item_Devices::getItemAffinities($this->item->getType());

      foreach ($this->devices as $itemdevicetype => $value) {
         if ($itemdevicetype !== false && in_array($itemdevicetype, $this->devicetypes)) {
            $itemdevice = new $itemdevicetype;

            $itemdevicetable = getTableForItemType($itemdevicetype);
            $devicetype      = $itemdevicetype::getDeviceType();
            $device          = new $devicetype;
            $devicetable     = getTableForItemType($devicetype);
            $fk              = getForeignKeyFieldForTable($devicetable);

            $iterator = $DB->request([
               'SELECT'    => [
                  "$itemdevicetable.$fk",
               ],
               'FROM'      => $itemdevicetable,
               'WHERE'     => [
                  "$itemdevicetable.items_id"     => $this->item->fields['id'],
                  "$itemdevicetable.itemtype"     => $this->item->getType(),
                  "$itemdevicetable.is_dynamic"   => 1
               ]
            ]);

            $existing = [];
            while ($row = $iterator->next()) {
               $existing[$row[$fk]] = $row[$fk];
            }

            foreach ($value as $val) {
               if (!isset($val->designation) || $val->designation == '') {
                  //cannot be empty
                  $val->designation = $itemdevice->getTypeName(1);
               }

               $device_id = $device->import((array)$val);
               if (!in_array($device_id, $existing)) {
                  $itemdevice_data = [
                     $fk                  => $device_id,
                     'itemtype'           => $this->item->getType(),
                     'items_id'           => $this->item->fields['id'],
                     'is_dynamic'         => 1,
                     //'_no_history'        => $no_history
                  ] + (array)$val;
                  $itemdevice->add($itemdevice_data, []/*, !$no_history*/);
               }
            }
         }

         if ($itemdevicetype === 'Item_DeviceNetworkCard' && count($this->data['networks__ports'])) {
            $this->handleNetworkPort($this->data['networks__ports']/*, $no_history*/);
         }

      }
   }

   /**
    * Is current data a drive
    *
    * @return boolean
    */
   public function isDrive($data) {
      $drives_regex = [
         'rom',
         'dvd',
         'blu[\s-]*ray',
         'reader',
         'sd[\s-]*card',
         'micro[\s-]*sd',
         'mmc'
      ];

      foreach ($drives_regex as $regex) {
         foreach (['type', 'model', 'name'] as $field) {
            if (property_exists($data, $field)
               && !empty($data->$field)
               && preg_match("/".$regex."/i", $data->$field)
            ) {
               return true;
            }
         }
      }

      return false;
   }

   /**
    * Manage network ports
    *
    * @param array   $inventory_networkports
    * @param integer $computers_id
    * @param boolean $no_history
    *
    * @return void
    */
   public function handleNetworkPort($inventory_networkports, $no_history = true) {
      global $DB;

      $_SESSION["plugin_fusioninventory_entity"] = 0;//FIXME: HACK
      $computers_id = $this->item->fields['id'];
      $networkPort = new \NetworkPort();
      $networkName = new \NetworkName();
      $iPAddress   = new \IPAddress();
      $iPNetwork   = new \IPNetwork();
      $item_DeviceNetworkCard = new \Item_DeviceNetworkCard();

      foreach ($inventory_networkports as $a_networkport) {
         $a_networkport = (array)$a_networkport;
         if ($a_networkport['mac'] != '') {
            $a_networkports = $networkPort->find(
                  ['mac'      => $a_networkport['mac'],
                   'itemtype' => 'PluginFusioninventoryUnmanaged'],
                  [], 1);
            if (count($a_networkports) > 0) {
               $input = current($a_networkports);
               $unmanageds_id = $input['items_id'];
               $input['logical_number'] = $a_networkport['logical_number'];
               $input['itemtype'] = 'Computer';
               $input['items_id'] = $computers_id;
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
               'items_id'     => $computers_id,
               'itemtype'     => 'Computer',
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
      foreach ($inventory_networkports as $key => $a_networkport) {
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
                     'entities_id' => $_SESSION["plugin_fusioninventory_entity"],
                  ]) == 0) {

               $input_ipanetwork = [
                   'name'    => $a_networkport['subnet'].'/'.
                                $a_networkport['netmask'].' - '.
                                $a_networkport['gateway'],
                   'network' => $a_networkport['subnet'].' / '.
                                $a_networkport['netmask'],
                   'gateway' => $a_networkport['gateway'],
                   'entities_id' => $_SESSION["plugin_fusioninventory_entity"]
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
               if ($inventory_networkports[$key]->logical_number != $logical_number) {
                  $input = [];
                  $input['id'] = $keydb;
                  $input['logical_number'] = $inventory_networkports[$key]->logical_number;
                  $networkPort->update($input, !$no_history);
               }

               // Add / update instantiation_type
               if (isset($inventory_networkports[$key]->instantiation_type)) {
                  $instantiation_type = $inventory_networkports[$key]->instantiation_type;
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

                     if (isset($inventory_networkports[$key]->speed)) {
                        $input['speed'] = $inventory_networkports[$key]->speed;
                        $input['speed_other_value'] = $inventory_networkports[$key]->speed;
                     }
                     if (isset($inventory_networkports[$key]->wwn)) {
                        $input['wwn'] = $inventory_networkports[$key]->wwn;
                     }
                     if (isset($inventory_networkports[$key]->mac)) {
                        $networkcards = $item_DeviceNetworkCard->find(
                                ['mac'      => $inventory_networkports[$key]->mac,
                                 'itemtype' => 'Computer',
                                 'items_id' => $computers_id],
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
                  $a_networkport['entities_id'] = $_SESSION["plugin_fusioninventory_entity"];
                  $a_networkport['items_id'] = $computers_id;
                  $a_networkport['itemtype'] = "Computer";
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
               $a_computerinventory_ipaddress = $inventory_networkports[$key]->ipaddress;
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
               unset($inventory_networkports[$key]);
               break;
            }
         }
      }

      if (count($inventory_networkports) == 0
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
         if (count($inventory_networkports) != 0) {
            foreach ($inventory_networkports as $a_networkport) {
               $a_networkport = (array)$a_networkport;
               $a_networkport['entities_id'] = $_SESSION["plugin_fusioninventory_entity"];
               $a_networkport['items_id'] = $computers_id;
               $a_networkport['itemtype'] = "Computer";
               $a_networkport['is_dynamic'] = 1;
               $a_networkport['_no_history'] = $no_history;
               $a_networkport['items_id'] = $networkPort->add($a_networkport, [], !$no_history);
               unset($a_networkport['_no_history']);
               $a_networkport['is_recursive'] = 0;
               $a_networkport['itemtype'] = 'NetworkPort';
               unset($a_networkport['name']);
               $a_networkport['_no_history'] = $no_history;
               $a_networknames_id = $networkName->add($a_networkport, [], !$no_history);

               \Toolbox::logWarning($a_networkport);
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
                                 'itemtype' => 'Computer',
                                 'items_id' => $computers_id],
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
}
