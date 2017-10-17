<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2017 Teclib' and contributors.
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
header("Content-Type: application/json; charset=UTF-8");
Html::header_nocache();

Session::checkLoginUser();

$success = false;
if (!isset($_POST['id']) || !isset($_POST['position']) || !isset($_POST['hpos']) || !isset($_POST['orientation'])) {
   Session::addMessageAfterRedirect(
      __('A required data is missing'),
      true,
      ERROR
   );
} else {
   $id = $_POST['id'];
   $itemrack = new Item_Rack();
   $itemrack->getFromDB($id);
   $success = $itemrack->update([
      'id'           => $id,
      'position'     => $_POST['position'],
      'hpos'         => $_POST['hpos'],
      'orientation'  => $_POST['orientation']
   ]);
}
echo json_encode(['success' => $success]);
