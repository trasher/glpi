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

      $ports = [];

      foreach ($this->data as $key => &$value) {
         $assettype = false;

         switch ($key) {
            case 'accesslog':
               //not used
               unset($this->data[$key]);
               break;
            case 'accountinfo':
               //handled from handleItem
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
               $mapping = [
                  'name'           => 'name',
                  'winprodid'      => 'licenseid',
                  'winprodkey'     => 'license_number',
                  'workgroup'      => 'domains_id',
                  'uuid'           => 'uuid',
                  'lastloggeduser' => 'users_id',
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
               //handled from peripheral
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
               $rulecollection = new \RuleDictionnaryPrinterCollection();

               foreach ($value as $k => &$val) {
                  $val->is_dynamic = 1;
                  if (strstr($val->port, "USB")) {
                     $val->have_usb = 1;
                  } else {
                     $val->have_usb = 0;
                  }
                  unset($val->port);

                  // Hack for USB Printer serial
                  if (property_exists($val, 'serial')
                        && preg_match('/\/$/', $val->serial)) {
                     $val->serial = preg_replace('/\/$/', '', $val->serial);
                  }

                  $res_rule = $rulecollection->processAllRules(['name' => $val->name]);
                  if ((!isset($res_rule['_ignore_ocs_import']) || $res_rule['_ignore_ocs_import'] != "1")
                     && (!isset($res_rule['_ignore_import']) || $res_rule['_ignore_import'] != "1")
                  ) {
                     if (isset($res_rule['name'])) {
                        $val->name = $res_rule['name'];
                     }
                     if (isset($res_rule['manufacturer'])) {
                        $val->manufacturers_id = $res_rule['manufacturer'];
                     }
                     $this->linked_items['Printer'][] = $val;
                  }
               }
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
               //handled from handleItem
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
               $asset->setExtraData($this->data);
               $asset->prepare();
               $value = $asset->handleLinks();
               $this->assets[$assettype][] = $asset;
            } else {
               unset($this->data[$key]);
            }
         }
      }
   }

   public function handleItem() {

      $item_input = ['is_dynamic' => 1];
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

      if (isset($this->assets['\Glpi\Inventory\Asset\NetworkCard'])) {
         foreach ($this->assets['\Glpi\Inventory\Asset\NetworkCard'] as $networkcard) {
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
    * Get agent
    *
    * @return \Agent
    */
   public function getAgent() {
      return $this->agent;
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
      $item_input = $this->data['glpi_' . $this->item->getType()];
      $entities_id = 0; //FIXME: should not be hardcoded
      $item_input['entities_id'] = $entities_id;
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
            $asset->handle();
            $ignored_controllers = array_merge($ignored_controllers, $asset->getIgnored('controllers'));
         }
      }

      //do controlers
      foreach ($controllers as $asset) {
         //do not handle ignored controllers
         $asset->setExtraData(['ignored' => $ignored_controllers]);
         $asset->handle();
      }
   }
}
