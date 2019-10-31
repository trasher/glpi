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

namespace Glpi\Inventory\Asset;

abstract class InventoryAsset
{
   /** @var array */
   protected $data;
   /** @var CommonDBTM */
   protected $item;
   /** @var array */
   protected $extra_data = [];

   /**
    * Constructor
    *
    * @param array $data Data part, optional
    */
   public function __construct(\CommonDBTM $item, array $data = null) {
      $this->item = $item;
      if ($data !== null) {
         $this->data = $data;
      }
   }

   /**
    * Set data from raw data part
    *
    * @param array $data Data part
    *
    * @return InventoryAsset
    */
   public function setData(array $data) {
      $this->data = $data;
      return $this;
   }

   /**
    * Prepare data from raw data part
    */
   abstract public function prepare() :array;

   /**
    * Handle in database
    */
   abstract public function handle();

   /**
    * Set extra sub parts of interest
    *
    * @param array $data Processed data
    *
    * @return InventoryAsset
    */
   public function setExtraData($data) {
      foreach (array_keys($this->extra_data) as $extra) {
         $this->extra_data[$extra] = $data[$extra];
      }
      return $this;
   }
}
