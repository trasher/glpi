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
   /** @var Agent */
   private $agent;
   /** @var InventoryAsset[] */
   private $assets;

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
         $this->agent = new \Agent();
         $this->agent->handleAgent($this->metadata);

         $this->item = new $this->agent->fields['itemtype'];

         if (!empty($this->agent->fields['id'])) {
            $this->item->getFromDB($this->agent->fields['id']);
         }

         $this->processInventoryData();
         $this->handleItem();

         $this->handleAssets();
         $this->handleDevices();
         $this->handleNetworkPorts();

         $this->errors[] = 'Inventory is WIP';

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
         $assettype = false;

         switch ($key) {
            case 'accesslog':
            case 'accountinfo':
               break;
            case 'cpus':
               $assettype = '\Glpi\Inventory\Asset\Processor';
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
               $value = [$val];
               break;
            case 'inputs':
            case 'local_groups':
            case 'local_users':
            case 'physical_volumes':
            case 'volume_groups':
            case 'logical_volumes':
               break;
            case 'memories':
               $assettype = '\Glpi\Inventory\Asset\Memory';
               break;
            case 'monitors':
               $assettype = '\Glpi\Inventory\Asset\Monitor';
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

                     if (property_exists($val_port, 'mac')) {
                        $val_port->mac = strtolower($val_port->mac);
                        $portkey = $val_port->name . '-' . $val_port->mac;
                     } else {
                        $portkey = $val_port->name; //FIXME: not sure for this one
                     }

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
                                 } else if (property_exists($val_port, 'mac') && $val_port->mac != '') {
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
                        if (property_exists($val_port, 'mac') && !empty($val_port->mac)) {
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
            case 'networks__ports':
               //nothing to do, this one has been created from 'networks'
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
               $suffix = '__hdd';
               $this->data[$key . $suffix] = $hdd;
               $altitemdevicetype = 'Item_DeviceHardDrive';
               break;
            case 'storages__hdd':
               //nothing to do, this one has been created from 'storages'
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

         //TODO: all types should pass here?
         if ($assettype !== false) {
            $asset = new $assettype($this->item, $value);
            $value = $asset->prepare();
            $this->assets[$assettype][] = $asset;
         }

         //create mapping for existing devices
         if ($itemdevicetype !== false) {
            $this->devices[$itemdevicetype] = $key;
            if (isset($altitemdevicetype)) {
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

      foreach ($this->devices as $itemdevicetype => $key) {
         if ($itemdevicetype !== false && in_array($itemdevicetype, $this->devicetypes)) {
            $value = $this->data[$key];
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
    * @return void
    */
   public function handleNetworkPorts() {
      global $DB;

      $inventory_networkports = $this->data['networks__ports'];
      $no_history = true;

      $_SESSION["plugin_fusioninventory_entity"] = 0;//FIXME: HACK
      $computers_id = $this->item->fields['id'];
      $networkPort = new \NetworkPort();
      $networkName = new \NetworkName();
      $iPAddress   = new \IPAddress();
      $iPNetwork   = new \IPNetwork();
      $item_DeviceNetworkCard = new \Item_DeviceNetworkCard();

      foreach ($inventory_networkports as $a_networkport) {
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

   public function handleItem() {

      $item_input = [];
      if (isset($this->data['hardware'])) {
         $hardware = (object)$this->data['hardware'][0];
         foreach ($hardware as $key => $property) {
            $item_input[$key] = $property;
         }
      }

      // * BIOS
      if (isset($this->data['bios'])) {
         $bios = (object)$this->data['bios'][0];
         if (property_exists($bios, 'assettag')
               && !empty($bios->assettag)) {
            $item_input['otherserial'] = $bios->assettag;
         }
         if (property_exists($bios, 'smanufacturer')
               && !empty($bios->smanufacturer)) {
            $item_input['manufacturers_id'] = $bios->smanufacturer;
         } else if (property_exists($bios, 'mmanufacturer')
               && !empty($bios->mmanufacturer)) {
            $item_input['manufacturers_id'] = $bios->mmanufacturer;
            $item_input['mmanufacturer'] = $bios->mmanufacturer;
         } else if (property_exists($bios, 'bmanufacturer')
               && !empty($bios->bmanufacturer)) {
            $item_input['manufacturers_id'] = $bios->bmanufacturer;
            $item_input['bmanufacturer'] = $bios->bmanufacturer;
         }

         if (property_exists($bios, 'smodel') && $bios->smodel != '') {
            $item_input['computermodels_id'] = $bios->smodel;
         } else if (property_exists($bios, 'mmodel') && $bios->mmodel != '') {
            $item_input['computermodels_id'] = $bios->mmodel;
            $item_input['model'] = $bios->mmodel;
         }

         if (property_exists($bios, 'ssn')) {
            $item_input['serial'] = trim($bios->ssn);
            // HP patch for serial begin with 'S'
            if (isset($item_input['manufacturers_id'])
                  && strstr($item_input['manufacturers_id'], "ewlett")
                  && preg_match("/^[sS]/", $item_input['serial'])) {
               $item_input['serial'] = trim(
                  preg_replace(
                     "/^[sS]/",
                     "",
                     $item_input['serial']
                  )
               );
            }
         }

         if (property_exists($bios, 'msn')) {
            $item_input['mserial'] = $bios->msn;
         }
      }

      // otherserial (on tag) if defined in config
      // TODO: andle config
      if (!isset($item_input['otherserial'])) {
         if (isset($this->data['accountinfo'])) {
            $ainfos = (object)$this->data['accountinfo'];
            if ($ainfos->keyname == 'TAG' && $ainfos->keyvalue != '') {
               $item_input['otherserial'] = $ainfos->keyvalue;
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
            $item_input['computertypes_id'] = $hardware->vmsystem;
            // HACK FOR BSDJail, remove serial and UUID (because it's of host, not contener)
            if ($hardware->vmsystem == 'BSDJail') {
               if (isset($item_input['serial'])) {
                  $item_input['serial'] = '';
               }
               $item_input['uuid'] .= '-' . $item_input['name'];
            }

         } else {
            //It's not a virtual machine, then check :
            //1 - HARDWARE/CHASSIS_TYPE
            //2 - BIOS/TYPE
            //3 - BIOS/MMODEL
            //4 - HARDWARE/VMSYSTEM (should not go there)
            if (property_exists($hardware, 'chassis_type')
                  && !empty($hardware->chassis_type)) {
               $item_input['computertypes_id'] = $hardware->chassis_type;
            } else if (isset($bios) && property_exists($bios, 'type')
                  && !empty($bios->type)) {
               $item_input['computertypes_id'] = $bios->type;
            } else if (isset($bios) && property_exists($bios, 'mmodel')
                  && !empty($bios->mmodel)) {
               $item_input['computertypes_id'] = $bios->mmodel;
            } else if (property_exists($hardware, 'vmsystem')
                  && !empty($hardware->vmsystem)) {
               $item_input['computertypes_id'] = $hardware->vmsystem;
            }
         }
      }

      $this->data['glpi_' . $this->item->getType()] = $item_input;

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

      //$a_computerinventory = PluginFusioninventoryFormatconvert::computerInventoryTransformation( $arrayinventory['CONTENT']);

      // Get tag is defined and put it in fusioninventory_agent table
      /*$tagAgent = "";
      if (isset($a_computerinventory['ACCOUNTINFO'])) {
         if (isset($a_computerinventory['ACCOUNTINFO']['KEYNAME'])
              && $a_computerinventory['ACCOUNTINFO']['KEYNAME'] == 'TAG') {
            if (isset($a_computerinventory['ACCOUNTINFO']['KEYVALUE'])
                    && $a_computerinventory['ACCOUNTINFO']['KEYVALUE'] != '') {
               $tagAgent = $a_computerinventory['ACCOUNTINFO']['KEYVALUE'];
            }
         }
      }
      $pfAgent = new PluginFusioninventoryAgent();
      $input = [];
      $input['id'] = $_SESSION['plugin_fusioninventory_agents_id'];
      $input['tag'] = $tagAgent;
      $pfAgent->update($input);*/

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

      if (isset($item_input['serial']) && !empty($item_input['serial'])) {
         $input['serial'] = $item_input['serial'];
      }
      if (isset($item_input['otherserial']) && !empty($item_input['otherserial'])) {
         $input['otherserial'] = $item_input['otherserial'];
      }
      if (isset($item_input['uuid']) && !empty($item_input['uuid'])) {
         $input['uuid'] = $item_input['uuid'];
      }
      if (isset($this->device_id) && !empty($this->device_id)) {
         $input['device_id'] = $this->device_id;
      }

      if (isset($this->data['networks__ports'])) {
         foreach ($this->data['networks__ports'] as $network) {
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
            foreach ($this->data['networks__ports'] as $network) {
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
      if (isset($item_input['computermodels_id'])
            && !empty($item_input['computermodels_id'])
      ) {
         $input['model'] = $item_input['computermodels_id'];
      }
      if (isset($item_input['domains_id'])
            && !empty($item_input['domains_id'])
      ) {
         $input['domains_id'] = $item_input['domains_id'];
      }

      //$input['tag'] = $tagAgent;

      if (isset($item_input['name']) && !empty($item_input['name'])) {
         $input['name'] = $item_input['name'];
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
    */
   public function rulepassed($items_id, $itemtype) {
      global $DB, $PLUGIN_FUSIONINVENTORY_XML, $PF_ESXINVENTORY, $CFG_GLPI;

      $no_history = false;
      $setdynamic = 1;
      $item_input = $this->data['glpi_' . $this->item->getType()];
      $entities_id = 0; //FIXME: should not be hardcoded
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

         $items_id = $this->item->add($item_input);
         $this->agent->update(['id' => $this->agent->fields['id'], 'items_id' => $items_id]);

         $no_history = true;
         $setdynamic = 0;
         //$_SESSION['glpi_fusionionventory_nolock'] = true;
      } else {
         $this->item->getFromDB($items_id);
      }

      $item_input['id'] = $this->item->fields['id'];

      /*PluginFusioninventoryToolbox::logIfExtradebug(
         "pluginFusioninventory-rules",
         "Rule passed : ".$items_id.", ".$itemtype."\n"
      );*/
      /*$pfFormatconvert = new \PluginFusioninventoryFormatconvert();

      $a_computerinventory = $pfFormatconvert->replaceids(
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
            /*$item_input = $this->data['glpi_' . $this->item->getType()];
            $a_computerinventory['Computer']['states_id'] = $computer->fields['states_id'];
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

         $this->item->update($item_input, !$no_history);
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

         $plugin = new Plugin();
         if ($plugin->isActivated('monitoring')) {
            Plugin::doOneHook("monitoring", "ReplayRulesForItem", ['Computer', $items_id]);
         }*/
         // * For benchs
         //Toolbox::logInFile("exetime", (microtime(TRUE) - $start)." (".$items_id.")\n".
         //  memory_get_usage()."\n".
         //  memory_get_usage(TRUE)."\n".
         //  memory_get_peak_usage()."\n".
         //  memory_get_peak_usage()."\n");

         /*if (isset($_SESSION['plugin_fusioninventory_rules_id'])) {
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
            $pfRulematchedlog->add($inputrulelog, [], false);
            $pfRulematchedlog->cleanOlddata($items_id, $itemtype);
            unset($_SESSION['plugin_fusioninventory_rules_id']);
         }*/
         // Write XML file
         /*if (!empty($PLUGIN_FUSIONINVENTORY_XML)) {
            PluginFusioninventoryToolbox::writeXML(
                    $items_id,
                    $PLUGIN_FUSIONINVENTORY_XML->asXML(),
                    'computer');
         }*/
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


   public function handleAssets() {
      foreach ($this->assets as $type => $assets) {
         foreach ($assets as $asset) {
            $asset->handle();
         }
      }
   }
}
