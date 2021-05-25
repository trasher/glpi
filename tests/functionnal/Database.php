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

/* Test for inc/database.class.php */

class Database extends DbTestCase {

   public function testCreate() {
      $db = new \Database();

      $dbid = $db->add([
         'name' => 'Maria, maria',
         'instance_port' => 3306,
         'instance_size' => 52000
      ]);

      //check DB is created, and load it
      $this->integer($dbid)->isGreaterThan(0);
      $this->boolean($db->getFromDB($dbid))->isTrue();

      //check instance has been created
      $instances = $db->getInstances();
      $this->array($instances)->hasSize(1);
      $instance = $instances[0];
      $this->string($instance['name'])->isIdenticalTo('"Maria, maria" default instance');
      $this->string($instance['port'])->isIdenticalTo('3306');
      $this->integer($instance['size'])->isIdenticalTo(52000);
   }

   public function testInstanceName() {
      $db = new \Database();

      $dbid = $db->add([
         'name' => 'Another maria',
         'instance_name' => 'the instance',
         'instance_port' => 3306,
         'instance_size' => 52000
      ]);

      //check DB is created, and load it
      $this->integer($dbid)->isGreaterThan(0);
      $this->boolean($db->getFromDB($dbid))->isTrue();

      //check instance has been created
      $instances = $db->getInstances();
      $this->array($instances)->hasSize(1);
      $instance = $instances[0];
      $this->string($instance['name'])->isIdenticalTo('the instance');
      $this->string($instance['port'])->isIdenticalTo('3306');
      $this->integer($instance['size'])->isIdenticalTo(52000);
   }
}
