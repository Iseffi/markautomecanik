-- Copyright (C) 2026 Michel L'Hostis <michel.lhostis@iseffi.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.
--
-- GDPR opt-out list: contacts erased on request are never imported again.
-- Only salted hashes (HMAC-SHA256) of the email and of the Webmecanik contact id are stored.

CREATE TABLE llx_markautomecanik_optout(
	rowid          integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity         integer DEFAULT 1 NOT NULL,
	email_hash     varchar(64),
	wmid_hash      varchar(64),
	date_creation  datetime NOT NULL,
	fk_user        integer
) ENGINE=innodb;
