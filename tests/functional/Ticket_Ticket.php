<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2024 Teclib' and contributors.
 * @copyright 2003-2014 by the INDEPNET Development Team.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

namespace tests\units;

use DbTestCase;
use Psr\Log\LogLevel;

/* Test for inc/ticket_ticket.class.php */

class Ticket_Ticket extends DbTestCase
{
    private $tone;
    private $ttwo;

    private function createTickets()
    {
        $tone = new \Ticket();
        $this->integer(
            (int)$tone->add([
                'name'         => 'Linked ticket 01',
                'description'  => 'Linked ticket 01',
                'content'            => '',
            ])
        )->isGreaterThan(0);
        $this->boolean($tone->getFromDB($tone->getID()))->isTrue();
        $this->tone = $tone;

        $ttwo = new \Ticket();
        $this->integer(
            (int)$ttwo->add([
                'name'         => 'Linked ticket 02',
                'description'  => 'Linked ticket 02',
                'content'            => '',
            ])
        )->isGreaterThan(0);
        $this->boolean($ttwo->getFromDB($ttwo->getID()))->isTrue();
        $this->ttwo = $ttwo;
    }

    public function testSimpleLink()
    {
        $this->createTickets();
        $tone = $this->tone;
        $ttwo = $this->ttwo;

        $link = new \Ticket_Ticket();
        $lid = (int)$link->add([
            'tickets_id_1' => $tone->getID(),
            'tickets_id_2' => $ttwo->getID(),
            'link'         => \CommonITILObject_CommonITILObject::LINK_TO
        ]);
        $this->integer($lid)->isGreaterThan(0);

       //cannot add same link twice!
        $this->integer(
            (int)$link->add([
                'tickets_id_1' => $tone->getID(),
                'tickets_id_2' => $ttwo->getID(),
                'link'         => \CommonITILObject_CommonITILObject::LINK_TO
            ])
        )->isIdenticalTo(0);

       //but can be reclassed as a duplicate
        $this->integer(
            (int)$link->add([
                'tickets_id_1' => $tone->getID(),
                'tickets_id_2' => $ttwo->getID(),
                'link'         => \CommonITILObject_CommonITILObject::DUPLICATE_WITH
            ])
        )->isGreaterThan(0);
        $this->boolean($link->getFromDB($lid))->isFalse();

       //cannot eclass from duplicate to simple link
        $this->integer(
            (int)$link->add([
                'tickets_id_1' => $tone->getID(),
                'tickets_id_2' => $ttwo->getID(),
                'link'         => \CommonITILObject_CommonITILObject::LINK_TO
            ])
        )->isIdenticalTo(0);
    }

    public function testSonsParents()
    {
        $this->createTickets();
        $tone = $this->tone;
        $ttwo = $this->ttwo;

        $link = new \Ticket_Ticket();
        $this->integer(
            (int)$link->add([
                'tickets_id_1' => $tone->getID(),
                'tickets_id_2' => $ttwo->getID(),
                'link'         => \CommonITILObject_CommonITILObject::SON_OF
            ])
        )->isGreaterThan(0);

       //cannot add same link twice!
        $link = new \Ticket_Ticket();
        $this->integer(
            (int)$link->add([
                'tickets_id_1' => $tone->getID(),
                'tickets_id_2' => $ttwo->getID(),
                'link'         => \CommonITILObject_CommonITILObject::SON_OF
            ])
        )->isIdenticalTo(0);

        $this->createTickets();
        $tone = $this->tone;
        $ttwo = $this->ttwo;

        $link = new \Ticket_Ticket();
        $this->integer(
            (int)$link->add([
                'tickets_id_1' => $tone->getID(),
                'tickets_id_2' => $ttwo->getID(),
                'link'         => \CommonITILObject_CommonITILObject::PARENT_OF
            ])
        )->isGreaterThan(0);
        $this->boolean($link->getFromDB($link->getID()))->isTrue();

       //PARENT_OF is stored as inversed child
        $this->array($link->fields)
         ->integer['tickets_id_1']->isIdenticalTo($ttwo->getID())
         ->integer['tickets_id_2']->isIdenticalTo($tone->getID())
         ->integer['link']->isEqualTo(\CommonITILObject_CommonITILObject::SON_OF);
    }

    /**
     * BC Test for getLinkedTicketsTo
     * @return void
     */
    public function testGetLinkedTicketsTo()
    {
        // Create ticket
        $ticket = new \Ticket();
        $tickets_id = $ticket->add([
            'name'     => 'test',
            'content'  => 'test',
            'status'   => \Ticket::INCOMING
        ]);
        $this->integer((int)$tickets_id)->isGreaterThan(0);

        // Create 5 other tickets
        $tickets = [];
        for ($i = 0; $i < 5; $i++) {
            $linked_tickets_id = $ticket->add([
                'name'     => 'test' . $i,
                'content'  => 'test' . $i,
                'status'   => \Ticket::INCOMING
            ]);
            $this->integer((int)$linked_tickets_id)->isGreaterThan(0);
            $tickets[] = $linked_tickets_id;
        }

        // Link the first ticket to the others
        $link = new \Ticket_Ticket();
        foreach ($tickets as $linked_ticket_id) {
            $this->integer(
                (int)$link->add([
                    'tickets_id_1' => $tickets_id,
                    'tickets_id_2' => $linked_ticket_id,
                    'link'         => \CommonITILObject_CommonITILObject::LINK_TO
                ])
            )->isGreaterThan(0);
        }

        $linked = @\Ticket_Ticket::getLinkedTicketsTo((int) $tickets_id);
        $this->array($linked)->hasSize(5);
        for ($i = 0; $i < 5; $i++) {
            $linked = @\Ticket_Ticket::getLinkedTicketsTo((int) $tickets[$i]);
            $this->array($linked)->hasSize(1);
        }
    }

    public function testRestrictedGetLinkedTicketsTo()
    {
        $this->login();
        $this->createTickets();

        $ticket_ticket = new \Ticket_Ticket();
        $this->integer($ticket_ticket->add([
            'tickets_id_1' => $this->tone->getID(),
            'tickets_id_2' => $this->ttwo->getID(),
            'link'         => \Ticket_Ticket::LINK_TO
        ]))->isGreaterThan(0);

        $ticket = new \Ticket();
        $this->integer($other_tickets_id = $ticket->add([
            'name'      => 'Linked ticket 03',
            'content'   => 'Linked ticket 03',
            'users_id'  => $_SESSION['glpiID'] + 1, // Not current user
            '_skip_auto_assign' => true,
            'entities_id' => $this->getTestRootEntity(true),
        ]))->isGreaterThan(0);

        $this->integer($ticket_ticket->add([
            'tickets_id_1' => $this->tone->getID(),
            'tickets_id_2' => $other_tickets_id,
            'link'         => \Ticket_Ticket::LINK_TO
        ]))->isGreaterThan(0);

        $linked = @\Ticket_Ticket::getLinkedTicketsTo($this->tone->getID());
        $this->integer(count($linked))->isEqualTo(2);
        $this->array(array_column($linked, 'tickets_id'))->containsValues([$this->ttwo->getID(), $other_tickets_id]);

        // Remove READALL ticket permission
        $_SESSION['glpiactiveprofile']['ticket'] = READ;
        $linked = @\Ticket_Ticket::getLinkedTicketsTo($this->tone->getID());
        $this->integer(count($linked))->isEqualTo(2);
        $this->array(array_column($linked, 'tickets_id'))->containsValues([$this->ttwo->getID(), $other_tickets_id]);
        // Get linked tickets using view restrictions
        $linked = @\Ticket_Ticket::getLinkedTicketsTo($this->tone->getID(), true);
        $this->integer(count($linked))->isEqualTo(1);
        $this->array(array_column($linked, 'tickets_id'))->containsValues([$this->ttwo->getID()]);
    }
}
