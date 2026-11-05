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
-- History of synchronisation runs (no personal data is stored here).

CREATE TABLE llx_markautomecanik_synclog(
	rowid         integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity        integer DEFAULT 1 NOT NULL,
	date_start    datetime NOT NULL,
	date_end      datetime,
	trigger_type  varchar(16) NOT NULL,
	status        smallint DEFAULT 0 NOT NULL,
	nb_received   integer DEFAULT 0 NOT NULL,
	nb_created    integer DEFAULT 0 NOT NULL,
	nb_updated    integer DEFAULT 0 NOT NULL,
	nb_skipped    integer DEFAULT 0 NOT NULL,
	nb_errors     integer DEFAULT 0 NOT NULL,
	message       varchar(1024),
	fk_user       integer
) ENGINE=innodb;
