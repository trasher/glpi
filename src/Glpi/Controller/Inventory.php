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

namespace Glpi\Controller;

use Slim\Http\Request;
use Slim\Http\Response;
use Search;
use Session;
use Dropdown;
use Toolbox;

class Inventory extends AbstractController implements ControllerInterface
{
    /**
     * path: '/inventory'
     *
     * @param Request  $request  Request
     * @param Response $response Response
     * @param array    $args     URL arguments
     *
     * @return void
     *
     * @Glpi\Annotation\Route(name="inventoryDispatcher", pattern="/inventory", method="POST")
     */
    public function dispatch(Request $request, Response $response, array $args)
    {
        $contents = $request->getBody()->getContents();
        if (empty($contents)) {
            $contents = "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>
<REQUEST>
  <DEVICEID>johanxps-2018-07-09-09-07-13</DEVICEID>
  <QUERY>INVENTORY</QUERY>
  <TOKEN>12345678</TOKEN>
</REQUEST>
";
        }
        $inventory_request = new \Glpi\Inventory\Request($contents);

        //DEBUG
        \Toolbox::logWarning(
            "XML response sent: ".$inventory_request->getResponse()
        );
        $response->write($inventory_request->getResponse());
        return $response
            ->withHeader('Content-Type', $inventory_request->getContentType())
            ->withHeader('Cache-Control', 'no-cache,no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Connection', 'close');
    }
}
