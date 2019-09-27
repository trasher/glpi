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

namespace tests\units\Glpi\Inventory\Asset;

include_once __DIR__ . '/../../../../abstracts/AbstractInventoryAsset.php';

/* Test for inc/inventory/asset/printer.class.php */

class Printer extends AbstractInventoryAsset {

   protected function assetProvider() :array {
      return [
         [
            'xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>
<REQUEST>
  <CONTENT>
    <PRINTERS>
      <DRIVER>HP Color LaserJet Pro MFP M476 PCL 6</DRIVER>
      <NAME>HP Color LaserJet Pro MFP M476 PCL 6</NAME>
      <NETWORK>0</NETWORK>
      <PORT>10.253.6.117</PORT>
      <PRINTPROCESSOR>hpcpp155</PRINTPROCESSOR>
      <RESOLUTION>600x600</RESOLUTION>
      <SHARED>0</SHARED>
      <SHARENAME>HP Color LaserJet Pro MFP M476 PCL 6  (1)</SHARENAME>
      <STATUS>Unknown</STATUS>
    </PRINTERS>
    <VERSIONCLIENT>FusionInventory-Inventory_v2.4.1-2.fc28</VERSIONCLIENT>
  </CONTENT>
  <DEVICEID>glpixps.teclib.infra-2018-10-03-08-42-36</DEVICEID>
  <QUERY>INVENTORY</QUERY>
  </REQUEST>",
            'asset'  => '{"driver": "HP Color LaserJet Pro MFP M476 PCL 6", "name": "HP Color LaserJet Pro MFP M476 PCL 6", "network": false, "printprocessor": "hpcpp155", "resolution": "600x600", "shared": false, "sharename": "HP Color LaserJet Pro MFP M476 PCL 6  (1)", "status": "Unknown", "have_usb": 0}'
         ]
      ];
   }

   /**
    * @dataProvider assetProvider
    */
   public function testPrepare($json, $expected) {
      $converter = new \Glpi\Inventory\Converter;
      $data = $converter->convert($json);
      $json = json_decode($data);

      $computer = getItemByTypeName('Computer', '_test_pc01');
      $asset = new \Glpi\Inventory\Asset\Printer($computer, $json->content->printers);
      $asset->setExtraData((array)$json->content);
      $result = $asset->prepare();
      $this->object($result[0])->isEqualTo(json_decode($expected));
   }

   /*
   FIXME: rules deny import of urrent printer. Need to find one that is currently inventoried
   public function testHandle() {
      $computer = getItemByTypeName('Computer', '_test_pc01');

      //first, check there are no controller linked to this computer
      $ico = new \Computer_Item();
      $this->boolean($ico->getFromDbByCrit(['computers_id' => $computer->fields['id'], 'itemtype' => 'Printer']))
           ->isFalse('A printer is already linked to computer!');

      //convert data
      $expected = $this->assetProvider()[0];

      $converter = new \Glpi\Inventory\Converter;
      $data = $converter->convert($expected['xml']);
      $json = json_decode($data);

      $computer = getItemByTypeName('Computer', '_test_pc01');
      $asset = new \Glpi\Inventory\Asset\Printer($computer, $json->content->printers);
      $asset->setExtraData((array)$json->content);
      $result = $asset->prepare();
      $this->object($result[0])->isEqualTo(json_decode($expected['asset']));

      $agent = new \Agent();
      $agent->getEmpty();
      $asset->setAgent($agent);

      //handle
      $asset->handleLinks();
      $asset->handle();
      $this->boolean($ico->getFromDbByCrit(['computers_id' => $computer->fields['id'], 'itemtype' => 'Printer']))
           ->isTrue('Printer has not been linked to computer :(');
   }*/
}
