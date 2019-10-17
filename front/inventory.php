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

include ('../inc/includes.php');

$inventory_request = new \Glpi\Inventory\Request();

try {
   if ($_SERVER['REQUEST_METHOD'] != 'POST') {
      $inventory_request->addError('Method not allowed');
   } else {
      $inventory_request->setCompression(
         isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : false
      );

      $contents = file_get_contents("php://input");
      $inventory_request->handleRequest($contents);
   }
} catch (\Exception $e) {
   $inventory_request->addError($e->getMessage());
}

//DEBUG
\Toolbox::logWarning(
   "XML response sent: ".$inventory_request->getResponse()
);

header('Content-Type: ' . $inventory_request->getContentType());
header('Cache-Control: no-cacheno-store');
header('Pragma: no-cache');
header('Connection: close');

echo $inventory_request->getResponse();
