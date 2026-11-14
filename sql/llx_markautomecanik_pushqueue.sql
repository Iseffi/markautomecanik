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
-- Queue of Dolibarr contacts and third parties to send to Webmecanik (filled by triggers,
-- processed by the scheduled job). No personal data: only object ids.

CREATE TABLE llx_markautomecanik_pushqueue(
	rowid          integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity         integer DEFAULT 1 NOT NULL,
	element_type   varchar(16) NOT NULL,
	fk_object      integer NOT NULL,
	action         varchar(8) DEFAULT 'upsert' NOT NULL,
	wm_id          integer,
	status         smallint DEFAULT 0 NOT NULL,
	attempts       integer DEFAULT 0 NOT NULL,
	date_creation  datetime NOT NULL,
	date_next_try  datetime NOT NULL,
	date_done      datetime,
	last_error     varchar(255)
) ENGINE=innodb;
