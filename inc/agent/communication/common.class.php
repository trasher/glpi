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

namespace Glpi\Agent\Communication;

abstract class Common {
   //possible status
   public const STATUS_OK = 0;
   public const STATUS_ERR = 1;
   public const STATUS_NONE = 2;

   //handled content types
   public const CONTENT_JSON = 'application/json';
   public const CONTENT_ZLIB = 'application/x-compress-zlib';
   public const CONTENT_GZIP = 'application/x-compress-gzip';
   public const CONTENT_XML = 'application/xml';

   //Global headers
   /**
    * "Content-Type" HTTP header
    *
    *
    *
    * @var string
    */
   protected $content_type;

   /**
    * "Accept" HTTP header
    *
    * Must follow RFC7231 - https://tools.ietf.org/html/rfc7231#page-38
    *
    * @var string
    */
   protected $accept;

   /**
    * "Pragma" HTTP header
    *
    * Avoid any caching done by the server
    *
    * @var string
    */
   private $pragma = 'no-cache';

   //GLPI agent headers
   /**
    * "GLPI-Agent-ID" HTTP header
    * Required
    *
    * Plain text UUID which can be reduced in a 128 bits raw id (ex. 3a609a2e-947f-4e6a-9af9-32c024ac3944)
    *
    * @var string
    */
   protected $glpi_agent_id;

   /**
    * "GLPI-Request-ID" HTTP header
    *
    * 8 digit hexadecimal string in higher case like 42E6A9AF1
    *
    * @var string
    */
   protected $glpi_request_id;

   protected $glpi_cryptokey_id;
   protected $glpi_proxy_id;

   /**
    * Request status
    *
    * @var int
    */
   protected $status = self::STATUS_NONE;

   /**
    * Message to send back
    *
    * @var string
    */
   protected $message;

   public function getAnswer(): object {
      $answer = [
         'status' => $this->getStatus(),
         'message'   => $this->getMessage()
      ];
      return (object)$answer;
   }

   public function getStatus(): int {
      return $this->status;
   }

   public function getMessage(): string {
      return $this->message;
   }
}