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
 */
class Request
{
    const DEFAULT_FREQUENCY = 24;

    const XML_MODE    = 0;
    const JSON_MODE   = 1;

   /** @var integer */
    private $mode; //will be usefull when agent will send json
   /** @var string */
    private $deviceid;
   /** @var SimpleXML */
    private $response;

   /**
    * @param null|mixed $data Request contents
    * @param integer    $mode One of self::*_MODE
    *
    * @return void
    */
    public function __construct($data = null, $mode = self::XML_MODE)
    {
        switch ($mode) {
            case self::XML_MODE:
                $this->response = new \SimpleXMLElement(
                    "<?xml version='1.0' encoding='UTF-8'?><REPLY></REPLY>"
                );
                break;
            case self::JSON_MODE:
                throw new \RuntimeException('JSON is not yet supported');
        }
        if (null !== $data) {
            $this->handleRequest($data);
        }
    }

   /**
    * Handle agent request
    *
    * @param mixed $data Sent data
    *
    * @return boolean
    */
    public function handleRequest($data)
    {
       //load and check data
        switch ($this->mode) {
            case self::XML_MODE:
                $this->handleXMLRequest($data);
                break;
            case self::JSON_MODE:
                $this->handleJSONRequest($data);
                break;
        }
    }

   /**
    * Handle XML request
    *
    * @param string $data Sent XML
    *
    * @return boolean
    */
    public function handleXMLRequest($data)
    {
        $xml = @simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml) {
            $xml_errors = libxml_get_errors();
            \Toolbox::logWarning('Invalid XML: ' . print_r($xml_errors, true));
            $this->addToResponse(['ERROR' => 'XML not well formed!']);
            return false;
        }

        $this->deviceid = $xml->REQUEST->DEVICEID;
        $query = $xml->REQUEST->QUERY;
        switch ($query) {
            case 'PROLOG':
                $this->prolog();
                break;
            case 'INVENTORY':
                $this->inventory();
                break;
            default:
                $this->addToResponse(['ERROR' => "Query '$query' is not supported."]);
                return false;
        }

        return true;
    }

   /**
    * Handle JSON request
    *
    * @param string $data Sent JSON
    *
    * @return boolean
    */
    public function handleJSONRequest($data)
    {
        throw new \RuntimeException('Not yet implemented.');
    }

    /**
     * Get request mode
     *
     * @return integer One of self::*_MODE
     */
    public function getMode()
    {
        return $this->mode;
    }

   /**
    * Add elements to response
    *
    * @param array $entries Array of key => values entries
    *
    * @return void
    */
    protected function addToResponse(array $entries)
    {
        $reply = $this->response->REPLY;
        foreach ($entries as $name => $content) {
            $this->addNode($reply, $name, $content);
        }
    }

   /**
    * Add node to response
    *
    * @param SimpleXMLElement  $parent  Parent element
    * @param string            $name    Element name to create
    * @param string|array|null $content Element contents, if any
    *
    * @return void
    */
    private function addNode($parent, $name, $content)
    {
        if (is_array($content)) {
            $node = $parent->addChild($name);
            foreach ($content as $sname => $scontent) {
                $this->addNode($node, $sname, $scontent);
            }
        } else {
            $parent->addChild($name, $content);
        }
    }

    /**
     * Get content-type
     *
     * @return string
     */
    public function getContentType()
    {
        switch ($this->mode) {
            case self::XML_MODE:
                return 'text/xml';
            case self::JSON_MODE:
                return 'application/json';
        }
    }

   /**
    * Get response
    *
    * @return object SimpleXMLElement
    */
    public function getResponse()
    {
        return $this->response;
    }

   /**
    * Handle agent problog request
    *
    * @return void
    */
    public function prolog()
    {
        $this->addToResponse([
         'PROLOG_FREQ'  => self::DEFAULT_FREQUENCY,
         'RESPONSE'     => 'SEND'
        ]);
    }

   /**
    * Handle agent inventory request
    *
    * @return void
    */
    public function inventory()
    {
        throw new \RuntimeException('Not implemented yet!');
    }
}
