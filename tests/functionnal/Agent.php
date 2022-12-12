<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2021 Teclib' and contributors.
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

namespace tests\units;

use DbTestCase;

class Agent extends DbTestCase {

   const INV_FIXTURES = GLPI_ROOT . '/vendor/glpi-project/inventory_format/examples/';

   public function testDefineTabs() {
      $expected = [
         'Agent$main'   => 'Agent',
         'Log$1'        => 'Historical'
      ];
      $this
         ->given($this->newTestedInstance)
            ->then
               ->array($this->testedInstance->defineTabs())
               ->isIdenticalTo($expected);
   }

   public function testHandleAgent() {
      $metadata = [
         'deviceid'  => 'glpixps-2018-07-09-09-07-13',
         'version'   => 'FusionInventory-Agent_v2.5.2-1.fc31',
         'itemtype'  => 'Computer',
         'tag'       => '000005'
      ];

      $this
         ->given($this->newTestedInstance)
            ->then
               ->integer($this->testedInstance->handleAgent($metadata))
               ->isGreaterThan(0);
   }

   public function testAgentFeaturesFromItem() {
      //run an inventory
      $json = json_decode(file_get_contents(self::INV_FIXTURES . 'computer_1.json'));
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
      $this->array($metadata)->hasSize(6)
         ->string['deviceid']->isIdenticalTo('glpixps-2018-07-09-09-07-13')
         ->string['version']->isIdenticalTo('FusionInventory-Agent_v2.5.2-1.fc31')
         ->string['itemtype']->isIdenticalTo('Computer')
         ->string['action']->isIdenticalTo('inventory')
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

      $this
         ->given($this->newTestedInstance)
            ->then
               ->boolean($this->testedInstance->getFromDB($agent['id']))
               ->isTrue();

      $item = $this->testedInstance->getLinkedItem();
      $this->object($item)->isInstanceOf('Computer');

      $this->array($this->testedInstance->guessAddresses())->isIdenticalTo([
         'glpixps',
         '192.168.1.142',
         '[fe80::b283:4fa3:d3f2:96b1]',
         '192.168.1.118',
         '[fe80::92a4:26c6:99dd:2d60]',
         '192.168.122.1'
      ]);

      $this->array($this->testedInstance->getAgentURLs())->isIdenticalTo([
         'http://glpixps:62354',
         'http://192.168.1.142:62354',
         'http://[fe80::b283:4fa3:d3f2:96b1]:62354',
         'http://192.168.1.118:62354',
         'http://[fe80::92a4:26c6:99dd:2d60]:62354',
         'http://192.168.122.1:62354',
         'https://glpixps:62354',
         'https://192.168.1.142:62354',
         'https://[fe80::b283:4fa3:d3f2:96b1]:62354',
         'https://192.168.1.118:62354',
         'https://[fe80::92a4:26c6:99dd:2d60]:62354',
         'https://192.168.122.1:62354'
      ]);

      //link a domain to item and see if adresses are still ok
      $domain = new \Domain();
      $did =$domain->add([
         'name'   => 'glpi-project.org'
      ]);
      $this->integer($did)->isGreaterThan(0);

      $ditem = new \Domain_Item();
      $this->integer(
         $ditem->add([
            'itemtype'     => $item->getType(),
            'items_id'     => $item->getID(),
            'domains_id'   => $did
         ])
      )->isGreaterThan(0);

      $this->array($this->testedInstance->guessAddresses())->isIdenticalTo([
         'glpixps',
         '192.168.1.142',
         '[fe80::b283:4fa3:d3f2:96b1]',
         '192.168.1.118',
         '[fe80::92a4:26c6:99dd:2d60]',
         '192.168.122.1',
         'glpixps.glpi-project.org'
      ]);
    }

    public function testAgentHasChanged()
    {
        //run an inventory
        $json = json_decode(file_get_contents(self::INV_FIXTURES . 'computer_1.json'));
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
        $this->array($metadata)->hasSize(6)
            ->string['deviceid']->isIdenticalTo('glpixps-2018-07-09-09-07-13')
            ->string['version']->isIdenticalTo('FusionInventory-Agent_v2.5.2-1.fc31')
            ->string['itemtype']->isIdenticalTo('Computer')
            ->string['action']->isIdenticalTo('inventory')
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
        $old_agents_id = $agent['id'];

        $this
            ->given($this->newTestedInstance)
            ->then
            ->boolean($this->testedInstance->getFromDB($agent['id']))
            ->isTrue();

        $item = $this->testedInstance->getLinkedItem();
        $this->object($item)->isInstanceOf('Computer');

        //play an update with changes
        $json = json_decode(file_get_contents(self::INV_FIXTURES . 'computer_1.json'));

        //change agent and therefore deviceid
        $json->content->versionclient = 'GLPI-Agent_v1';
        $json->deviceid = 'glpixps-2022-01-17-11-36-53';

        $CFG_GLPI["is_contact_autoupdate"] = 0;
        $inventory = new \Glpi\Inventory\Inventory($json);
        $CFG_GLPI["is_contact_autoupdate"] = 1; //reset to default

        if ($inventory->inError()) {
            foreach ($inventory->getErrors() as $error) {
                var_dump($error);
            }
        }
        $this->boolean($inventory->inError())->isFalse();
        $this->array($inventory->getErrors())->isEmpty();

        //check inventory metadata
        $metadata = $inventory->getMetadata();
        $this->array($metadata)->hasSize(6)
            ->string['deviceid']->isIdenticalTo('glpixps-2022-01-17-11-36-53')
            ->string['version']->isIdenticalTo('GLPI-Agent_v1')
            ->string['itemtype']->isIdenticalTo('Computer')
            ->string['action']->isIdenticalTo('inventory')
            ->string['tag']->isIdenticalTo('000005');
        $this->array($metadata['provider'])->hasSize(10);

        //check old agent has been dropped
        $agent = new \Agent();
        $this->boolean($agent->getFromDB($old_agents_id))->isFalse('Old Agent still exists!');
   }

    public function testStaleActions()
    {
        //run an inventory
        $json = json_decode(file_get_contents(self::INV_FIXTURES . 'computer_1.json'));
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
        $this->array($metadata)->hasSize(6)
            ->string['deviceid']->isIdenticalTo('glpixps-2018-07-09-09-07-13')
            ->string['version']->isIdenticalTo('FusionInventory-Agent_v2.5.2-1.fc31')
            ->string['itemtype']->isIdenticalTo('Computer')
            ->string['action']->isIdenticalTo('inventory')
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
            ->string['tag']->isIdenticalTo('000005')
            ->integer['agenttypes_id']->isIdenticalTo($agenttype['id']);
        $old_agents_id = $agent['id'];

        $this
            ->given($this->newTestedInstance)
            ->then
            ->boolean($this->testedInstance->getFromDB($agent['id']))
            ->isTrue();

        $item = $this->testedInstance->getLinkedItem();
        $this->object($item)->isInstanceOf('Computer');

        //check default status
        $this->integer($item->fields['states_id'])->isIdenticalTo(0);

        //create new status
        $state = new \State();
        $states_id = $state->add(['name' => 'Stale']);
        $this->integer($states_id)->isGreaterThan(0);

        //set last agent contact far ago
        $DB->update(
            \Agent::getTable(),
            ['last_contact' => date('Y-m-d H:i:s', strtotime('-1 year'))],
            ['id' => $agent['id']]
        );

        //define sale agents actions
        \Config::setConfigurationValues(
            'inventory',
            [
                'stale_agents_delay' => 1,
                'stale_agents_action' => exportArrayToDB([
                    \Glpi\Inventory\Conf::STALE_AGENT_ACTION_STATUS,
                    \Glpi\Inventory\Conf::STALE_AGENT_ACTION_TRASHBIN
                ]),
                'stale_agents_status' => $states_id
            ]
        );

        //run crontask
        $task = new \CronTask();
        $this->boolean(\Agent::cronCleanoldagents($task))->isTrue();

        //check item has been updated
        $this->boolean($item->getFromDB($item->fields['id']))->isTrue();
        $this->integer($item->fields['is_deleted'])->isIdenticalTo(1);
        $this->integer($item->fields['states_id'])->isIdenticalTo($states_id);
    }
}
