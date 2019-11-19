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
 * Both XML (legacy) and JSON inventory formats are supported.
 *
 * @see https://github.com/glpi-project/inventory_format/blob/master/inventory.schema.json
 */
class Request
{
    const DEFAULT_FREQUENCY = 24;

    const XML_MODE    = 0;
    const JSON_MODE   = 1;

    const PROLOG_QUERY = 'PROLOG';
    const INVENT_QUERY = 'INVENTORY';

    const COMPRESS_NONE = 0;
    const COMPRESS_ZLIB = 1;
    const COMPRESS_GZIP = 2;

   /** @var integer */
   private $mode; //will be usefull when agent will send json
   /** @var string */
   private $deviceid;
   /** @var SimpleXML */
   private $response;
    /** @var integer */
   private $compression;
   /** @var boolean */
   private $error = false;

   /**
    * @param null|mixed $data Request contents
    * @param integer    $mode One of self::*_MODE
    *
    * @return void
    */
   public function __construct($data = null, $mode = self::XML_MODE) {
      $this->mode = $mode;

      switch ($mode) {
         case self::XML_MODE:
            $this->response = new \DomDocument();
            $this->response->appendChild(
                $this->response->createElement('REPLY')
            );
              break;
         case self::JSON_MODE:
              throw new \RuntimeException('JSON is not yet supported');
              /*$this->response = [];*/
         default:
              throw new \RuntimeException("Unknown mode $mode");
      }
      if (null !== $data) {
          return $this->handleRequest($data);
      }
   }

   /**
    * Handle agent request
    *
    * @param mixed $data Sent data
    *
    * @return boolean
    */
   public function handleRequest($data) :bool {
      if ($this->compression !== self::COMPRESS_NONE) {
         switch ($this->compression) {
            case self::COMPRESS_ZLIB:
                $data = gzuncompress($data);
                break;
            case self::COMPRESS_GZIP:
                $data = gzdecode($data);
                break;
            case null:
                throw new \UnexpectedValueException("Compression mode has not been set");
            default:
                throw new \UnexpectedValueException("Unknown compression mode" . $this->compression);
         }
      }

       //load and check data
      switch ($this->mode) {
         case self::XML_MODE:
            return $this->handleXMLRequest($data);
         case self::JSON_MODE:
            return $this->handleJSONRequest($data);
      }
   }

    /**
     * Handle Query
     *
     * @param string $query   Query mode (one of self::*_QUERY)
     * @param mixed  $content Contents, optionnal
     *
     * @return boolean
     */
   private function handleQuery($query, $content = null) :bool {
      switch ($query) {
         case self::PROLOG_QUERY:
            $this->prolog();
            break;
         case self::INVENT_QUERY:
            $this->inventory($content);
            break;
         default:
            $this->addError("Query '$query' is not supported.");
            return false;
      }
       return true;
   }

   /**
    * Handle XML request
    *
    * @param string $data Sent XML
    *
    * @return boolean
    */
   public function handleXMLRequest($data) :bool {
      $xml = @simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA);
      if (!$xml) {
         $xml_errors = libxml_get_errors();
         \Toolbox::logWarning('Invalid XML: ' . print_r($xml_errors, true));
         $this->addError('XML not well formed!');
         return false;
      }
      $this->deviceid = (string)$xml->DEVICEID;
      $query = (string)$xml->QUERY;

      return $this->handleQuery($query, $xml);
   }

   /**
    * Handle JSON request
    *
    * @param string $data Sent JSON
    *
    * @return boolean
    */
   public function handleJSONRequest($data) :bool {
       $this->addError('JSON is not yet supported');
       return false;
       /*$data = json_decode($data);
       $this->deviceid = $data['deviceid'];
       return $this->handleQuery($data['query'], $data);*/
   }

    /**
     * Get request mode
     *
     * @return integer One of self::*_MODE
     */
   public function getMode() :int {
       return $this->mode;
   }

    /**
     * Adds an error
     *
     * @param string $message Error message
     *
     * @return void
     */
   public function addError($message) {
      $this->error = true;
      $this->addToResponse(['ERROR' => $message]);
   }

   /**
    * Add elements to response
    *
    * @param array $entries Array of key => values entries
    *
    * @return void
    */
   protected function addToResponse(array $entries) {
      if ($this->mode === self::XML_MODE) {
          $root = $this->response->documentElement;
         foreach ($entries as $name => $content) {
            $this->addNode($root, $name, $content);
         }
      } else {
         foreach ($entries as $name => $content) {
             $this->addEntry($this->response, $name, $content);
         }
      }
   }

   /**
    * Add node to response for XML_MODE
    *
    * @param SimpleXMLElement  $parent  Parent element
    * @param string            $name    Element name to create
    * @param string|array|null $content Element contents, if any
    *
    * @return void
    */
   private function addNode(\DomElement $parent, $name, $content) {
      if (is_array($content)) {
          $node = $parent->appendChild(
              $this->response->createElement(
                  $name
              )
          );
         foreach ($content as $sname => $scontent) {
            $this->addNode($node, $sname, $scontent);
         }
      } else {
          $parent->appendChild(
              $this->response->createElement(
                  $name,
                  $content
              )
          );
      }
   }

   /**
    * Add node to response for JSON_MODE
    *
    * @param array             $parent  Parent element
    * @param string            $name    Element name to create
    * @param string|array|null $content Element contents, if any
    *
    * @return void
    */
   private function addEntry(array &$parent, $name, $content) {
      if (is_array($content)) {
          $node = $parent[$name];
         foreach ($content as $sname => $scontent) {
            $this->addNode($node, $sname, $scontent);
         }
      } else {
          $parent[$name] = $content;
      }
   }


    /**
     * Get content-type
     *
     * @return string
     */
   public function getContentType() :string {
      switch ($this->mode) {
         case self::XML_MODE:
            return 'application/xml';
         case self::JSON_MODE:
            return 'application/json';
         default:
            throw new \RuntimeException("Unknown mode " . $this->mode);
      }
   }

   /**
    * Get response
    *
    * @return string
    */
   public function getResponse() :string {
      switch ($this->mode) {
         case self::XML_MODE:
            return $this->response->saveXML();
         case self::JSON_MODE:
            return json_encode($this->response);
         default:
            throw new \RuntimeException("Unknown mode " . $this->mode);
      }
   }

   /**
    * Handle agent problog request
    *
    * @return void
    */
   public function prolog() {
       $this->addToResponse([
        'PROLOG_FREQ'  => self::DEFAULT_FREQUENCY,
        'RESPONSE'     => 'SEND'
       ]);
   }

   /**
    * Handle agent inventory request
    *
    * @param aray $data Inventory input following specs
    *
    * @return void
    */
   public function inventory($data) {
       $inventory = new Inventory(
           $data,
           Inventory::FULL_MODE,
           $this->mode
       );

      if ($inventory->inError()) {
         foreach ($inventory->getErrors() as $error) {
            $this->addError($error);
         }
      } else {
          $this->addToResponse(['RESPONSE' => 'SEND']);
      }
   }

    /**
     * Detect compression algorithm from Content-Type header
     *
     * @param string $type Content type
     *
     * @return void
     */
   public function setCompression($type) {
      switch ($type) {
         case 'application/xml':
         case 'application/json':
            $this->compression = self::COMPRESS_NONE;
            break;
         case 'application/x-compress-zlib':
            $this->compression = self::COMPRESS_ZLIB;
            break;
         case 'application/x-compress-gzip':
            $this->compression = self::COMPRESS_GZIP;
            break;
         default:
            throw new \UnexpectedValueException("Unknown content type $type");
      }
   }

   /**
    * Is current request in error?
    *
    * @return boolean
    */
   public function inError() {
      return $this->error;
   }
}
