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

use Symfony\Component\Yaml\Yaml;

/**
 * Test class for src/Glpi/Inventory/Request.php
 */
class Request extends \GLPITestCase {
   public function testConstructor() {
      $request = new \Glpi\Inventory\Request;
      $this->integer($request->getMode())->isIdenticalTo(\Glpi\Inventory\Request::XML_MODE);
      $this->string($request->getResponse())->isIdenticalTo("<?xml version=\"1.0\"?>\n<REPLY/>\n");
      $this->string($request->getContentType())->isIdenticalTo('text/xml');

      $this->exception(
         function () {
            $request = new \Glpi\Inventory\Request(null, \Glpi\Inventory\Request::JSON_MODE);
            $this->integer($request->getMode())->isIdenticalTo(\Glpi\Inventory\Request::JSON_MODE);
            $this->string($request->getContentType())->isIdenticalTo('application/json');
         }
      )
         ->isInstanceOf('\RuntimeException')
         ->hasMessage('JSON is not yet supported');

      $this->exception(
         function () {
            $request = new \Glpi\Inventory\Request(null, 42);
         }
      )
         ->isInstanceOf('\RuntimeException')
         ->hasMessage('Unknown mode 42');
   }
}
