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
   /** @var Agent */
   private $agent;
   /** @var InventoryAsset[] */
   private $assets;
   /** @var \Glpi\Inventory\Conf */
   private $conf;
   /** @var array */
   private $benchs = [];

    /**
     * @param mixed   $data   Inventory data, optionnal
     * @param integer $mode   One of self::*_MODE
     * @param integer $format One of Request::*_MODE
     */
   public function __construct($data = null, $mode = self::FULL_MODE, $format = Request::JSON_MODE) {
       $this->mode = $mode;
       $this->conf = new Conf();

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
          return false;
      }

       $this->raw_data = json_decode($data);
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

         // Get tag if defined
       if (property_exists($this->raw_data->content, 'accountinfo')) {
          $ainfos = $this->raw_data->content->accountinfo;
          if (property_exists($ainfos, 'keyname')
             && $ainfos->keyname == 'TAG'
             && property_exists($ainfos, 'keyvalue')
             && $ainfos->keyvalue != ''
          ) {
             $this->metadata['tag'] = $ainfos->keyvalue;
          }
       }

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
         //bench
         $main_start = microtime(true);
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

         $this->errors[] = 'Inventory is WIP';

         $DB->commit();
      } catch (\Exception $e) {
         \Toolbox::logError($e);
         $DB->rollback();
         throw $e;
      } finally {
         // * For benchs
         $this->addBench($this->item->getType() . ' #'.$this->item->fields['id'], 'full', $main_start);
         $this->printBenchResults();
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
      $main = new Asset\Computer($this->item, $this->raw_data);//FIXME; what if not a computer?
      $main->setAgent($this->getAgent());
      $main->setExtraData($this->data);

      $item_start = microtime(true);
      $main->prepare();
      $this->addBench($this->item->getType(), 'prepare', $item_start);

      $this->mainasset = $main;
      if (isset($this->data['hardware'])) {
         //hardware is handled in computer, but may be used outside
         $this->data['hardware'] = $main->getHardware();
      }

      foreach ($this->data as $key => &$value) {
         $assettype = false;

         switch ($key) {
            case 'accesslog':
               //not used
               unset($this->data[$key]);
               break;
            case 'accountinfo':
               //handled from Asset\Computer
               break;
            case 'cpus':
               $assettype = '\Glpi\Inventory\Asset\Processor';
               break;
            case 'drives':
               $assettype = '\Glpi\Inventory\Asset\Volume';
               break;
            case 'envs':
               //not used
               unset($this->data[$key]);
               break;
            case 'firewalls':
               //not used
               break;
            case 'hardware':
               //handled from Asset\Computer
               break;
            case 'inputs':
               //handled from Asset\Peripheral
               break;
            case 'local_groups':
               //not used
               unset($this->data[$key]);
               break;
            case 'local_users':
               //not used
               unset($this->data[$key]);
               break;
            case 'physical_volumes':
               //not used
               unset($this->data[$key]);
               break;
            case 'volume_groups':
               //not used
               unset($this->data[$key]);
               break;
            case 'logical_volumes':
               //not used
               unset($this->data[$key]);
               break;
            case 'memories':
               $assettype = '\Glpi\Inventory\Asset\Memory';
               break;
            case 'monitors':
               $assettype = '\Glpi\Inventory\Asset\Monitor';
               break;
            case 'networks':
               $assettype = '\Glpi\Inventory\Asset\NetworkCard';
               break;
            case 'operatingsystem':
               $assettype = '\Glpi\Inventory\Asset\OperatingSystem';
               break;
            case 'ports':
               //not used
               unset($this->data[$key]);
               break;
            case 'printers':
               $assettype = '\Glpi\Inventory\Asset\Printer';
               break;
            case 'processes':
               //not used
               unset($this->data[$key]);
               break;
            case 'remote_mgmt':
               //not used - implemented in FI only
               unset($this->data[$key]);
               break;
            case 'slots':
               //not used
               unset($this->data[$key]);
               break;
            case 'softwares':
               $assettype = '\Glpi\Inventory\Asset\Software';
               break;
            case 'sounds':
               $assettype = '\Glpi\Inventory\Asset\SoundCard';
               break;
            case 'storages':
               $assettype = '\Glpi\Inventory\Asset\Drive';
               break;
            case 'usbdevices':
               $assettype = '\Glpi\Inventory\Asset\Peripheral';
               break;
            case 'antivirus':
               $assettype = '\Glpi\Inventory\Asset\Antivirus';
               break;
            case 'bios':
               $assettype = '\Glpi\Inventory\Asset\Firmware';
               break;
            case 'batteries':
               $assettype = '\Glpi\Inventory\Asset\Battery';
               break;
            case 'controllers':
               $assettype = '\Glpi\Inventory\Asset\Controller';
               break;
            case 'videos':
               $assettype = '\Glpi\Inventory\Asset\GraphicCard';
               break;
            case 'users':
               //handled from sset\Computer
               break;
            case 'versionclient':
               //not used
               unset($this->data[$key]);
               break;
            case 'versionprovider':
               //not used
               unset($this->data[$key]);
               break;
            case 'simcards':
               $assettype = '\Glpi\Inventory\Asset\Simcard';
               break;
            case 'virtualmachines':
               $assettype = '\Glpi\Inventory\Asset\VirtualMachine';
               break;
            case 'licenseinfos':
               //not used - implemented in FI only
               unset($this->data[$key]);
               break;
            case 'modems':
               //not used - implemented in FI only
               unset($this->data[$key]);
               break;

            default:
               //unhandled
               throw new \RuntimeException("Unhandled schema entry $key");
               break;
         }

         if ($assettype !== false) {
            $asset = new $assettype($this->item, $value);
            if ($asset->checkConf($this->conf)) {
               $asset->setAgent($this->getAgent());
               $asset->setExtraData($this->data);
               $asset_start = microtime(true);
               $asset->prepare();
               $value = $asset->handleLinks();
               $this->addBench($assettype, 'prepare', $asset_start);
               $this->assets[$assettype][] = $asset;
            } else {
               unset($this->data[$key]);
            }
         }
      }
   }

   public function handleItem() {
      //inject converted assets
      $this->mainasset->setExtraData($this->data);
      $item_start = microtime(true);
      $this->mainasset->handle();
      $this->addBench($this->item->getType(), 'handle', $item_start);
      return;
   }

   /**
    * Get agent
    *
    * @return \Agent
    */
   public function getAgent() {
      return $this->agent;
   }

   public function handleAssets() {
      $assets_list = $this->assets;

      $controllers = [];
      $ignored_controllers = [];

      //ensure controllers are done last, some components will
      //ask to ignore their associated constoller
      if (isset($assets_list['\Glpi\Inventory\Asset\Controller'])) {
         $controllers = $assets_list['\Glpi\Inventory\Asset\Controller'];
         unset($assets_list['\Glpi\Inventory\Asset\Controller']);
      }

      foreach ($assets_list as $type => $assets) {
         foreach ($assets as $asset) {
            $asset_start = microtime(true);
            $asset->handle();
            $this->addBench($type, 'handle', $asset_start);
            $ignored_controllers = array_merge($ignored_controllers, $asset->getIgnored('controllers'));
         }
      }

      //do controlers
      foreach ($controllers as $asset) {
         $asset_start = microtime(true);
         //do not handle ignored controllers
         $asset->setExtraData(['ignored' => $ignored_controllers]);
         $asset->handle();
         $this->addBench('\Glpi\Inventory\Asset\Controller', 'handle', $asset_start);
      }
   }

   /**
    * Add bench value
    *
    * @param string  $asset Asset
    * @param string  $type Either prepare or handle
    * @param integer $start Start time
    *
    * @return void
    */
   private function addBench($asset, $type, $start) {
      $exec_time = round(microtime(true) - $start, 5);
      $this->benchs[$asset][$type] = [
         'exectime'  => $exec_time,
         'mem'       => \Toolbox::getSize(memory_get_usage()),
         'mem_real'  => \Toolbox::getSize(memory_get_usage(true)),
         'mem_peak'  => \Toolbox::getSize(memory_get_peak_usage())

      ];
   }

   /**
    * Display bench results
    *
    * @return void
    */
   public function printBenchResults() {
      $output = '';
      foreach ($this->benchs as $asset => $types) {
         $output .= "$asset:\n";
         foreach ($types as $type => $data) {
            $output .= "\t$type:\n";
            foreach ($data as $key => $value) {
               $label = $key;
               switch ($label) {
                  case 'exectime':
                     $output .= "\t\tExcution time:       ";
                     break;
                  case 'mem':
                     $output .= "\t\tMemory usage:        ";
                     break;
                  case 'mem_real':
                     $output .= "\t\tMemory usage (real): ";
                     break;
                  case 'mem_peak':
                     $output .= "\t\tMemory peak:         ";
                     break;
               }

               if ($key == 'exectime') {
                  $output .= sprintf(
                     _n('%s second', '%s seconds', $value),
                     $value
                  );
               } else {
                  $output .= \Toolbox::getSize($value);
               }
               $output .= "\n";
            }
         }
      }

      \Toolbox::logInFile(
         "bench_inventory",
         $output
      );
   }
}
