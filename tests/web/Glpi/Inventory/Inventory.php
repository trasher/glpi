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

use \GuzzleHttp;

class Inventory extends \GLPITestCase {
   private $http_client;
   private $base_uri;

   public function beforeTestMethod($method) {
      global $CFG_GLPI;

      $this->http_client = new GuzzleHttp\Client();
      $this->base_uri    = trim($CFG_GLPI['url_base'], "/")."/";

      parent::beforeTestMethod($method);
   }

   public function testInventoryRequest() {
      $res = $this->http_client->request(
         'POST',
         $this->base_uri . 'front/inventory.php',
         [
            'headers' => [
               'Content-Type' => 'application/xml'
            ],
            'body'   => '<?xml version="1.0" encoding="UTF-8" ?>' .
                  "<REQUEST>
                  <CONTENT>
                     <VERSIONCLIENT>FusionInventory-Agent_v2.5.1-1.fc30</VERSIONCLIENT>
                     <VERSIONPROVIDER>
                        <COMMENTS>Platform  : linux buildvm-armv7-18.arm.fedoraproject.org 4.18.19-100.fc27.armv7hllpae 1 smp wed nov 14 21:55:54 utc 2018 armv7l armv7l armv7l gnulinux </COMMENTS>
                        <COMMENTS>Build date: Mon Jul  8 12:36:27 2019 GMT</COMMENTS>
                        <NAME>FusionInventory</NAME>
                        <PERL_ARGS>--debug --debug --logger=stderr --no-category=software,process,local_user,local_group,controller,environment</PERL_ARGS>
                        <PERL_CONFIG>gccversion: 9.2.1 20190827 (Red Hat 9.2.1-1)</PERL_CONFIG>
                        <PERL_CONFIG>defines: use64bitall use64bitint usedl usedtrace useithreads uselanginfo uselargefiles usemallocwrap usemultiplicity usemymalloc=n usenm=false useopcode useperlio useposix useshrplib usesitecustomize usethreads usevendorprefix usevfork=false</PERL_CONFIG>
                        <PERL_EXE>/usr/bin/perl</PERL_EXE>
                        <PERL_INC>/usr/share/fusioninventory/lib:/usr/local/lib64/perl5:/usr/local/share/perl5:/usr/lib64/perl5/vendor_perl:/usr/share/perl5/vendor_perl:/usr/lib64/perl5:/usr/share/perl5</PERL_INC>
                        <PERL_MODULE>LWP @ 6.39</PERL_MODULE>
                        <PERL_MODULE>LWP::Protocol @ 6.39</PERL_MODULE>
                        <PERL_MODULE>IO::Socket @ 1.39</PERL_MODULE>
                        <PERL_MODULE>IO::Socket::SSL @ 2.066</PERL_MODULE>
                        <PERL_MODULE>IO::Socket::INET @ 1.39</PERL_MODULE>
                        <PERL_MODULE>Net::SSLeay @ 1.85</PERL_MODULE>
                        <PERL_MODULE>Net::SSLeay uses OpenSSL 1.1.1d FIPS  10 Sep 2019</PERL_MODULE>
                        <PERL_MODULE>Net::HTTPS @ 6.19</PERL_MODULE>
                        <PERL_MODULE>HTTP::Status @ 6.18</PERL_MODULE>
                        <PERL_MODULE>HTTP::Response @ 6.18</PERL_MODULE>
                        <PERL_VERSION>v5.28.2</PERL_VERSION>
                        <PROGRAM>/usr/bin/fusioninventory-agent</PROGRAM>
                        <VERSION>2.5.1-1.fc30</VERSION>
                     </VERSIONPROVIDER>
                  </CONTENT>
                  <DEVICEID>computer-2018-07-09-09-07-13</DEVICEID>
                  <QUERY>INVENTORY</QUERY>
                  </REQUEST>"
         ]
      );
      $this->integer($res->getStatusCode())->isIdenticalTo(200);
      $this->string($res->getHeader('content-type')[0])->isIdenticalTo('application/xml');
      //FIXME: should send something else obviously
      $this->string((string)$res->getBody())
         ->isIdenticalTo("<?xml version=\"1.0\"?>\n<REPLY><ERROR>Inventory is not yet implemented</ERROR></REPLY>\n");

      //check agent in database
      $agent = new \Agent();
      $this->boolean($agent->getFromDBByCrit(['deviceid' => 'computer-2018-07-09-09-07-13']))->isTrue();

      $expected = [
         'deviceid'        => 'computer-2018-07-09-09-07-13',
         'version'         => '2.5.1-1.fc30',
         'agenttypes_id'   => 1,
         'locked'          => 0,
         'itemtype'        => 'Computer',
         'items_id'        => 0
      ];

      foreach ($expected as $key => $value) {
         if ($key === 'items_id') {
            //FIXME: retrieve created items_id
            $this->integer($agent->fields[$key])->isGreaterThan(0);
         } else {
            $this->variable($agent->fields[$key])->isEqualTo($value, "$key differs");
         }
      }
   }
}
