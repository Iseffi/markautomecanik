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
-- Marketing leads imported from Webmecanik Automation (or created manually / by REST API).

CREATE TABLE llx_markautomecanik_lead(
	rowid             integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity            integer DEFAULT 1 NOT NULL,
	date_creation     datetime NOT NULL,
	tms               timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat     integer,
	fk_user_modif     integer,
	import_key        varchar(14),
	origin            varchar(16) DEFAULT 'webmecanik' NOT NULL,
	wm_contact_id     integer,
	wm_date_added     datetime,
	wm_date_modified  datetime,
	wm_points         integer DEFAULT 0,
	firstname         varchar(128),
	lastname          varchar(128),
	email             varchar(255),
	phone             varchar(64),
	company           varchar(255),
	city              varchar(128),
	country           varchar(128),
	lang              varchar(16),
	website           varchar(255),
	industry          varchar(255),
	interests         text,
	message           text,
	utm_source        varchar(255),
	utm_medium        varchar(255),
	utm_campaign      varchar(255),
	status            smallint DEFAULT 1 NOT NULL,
	date_status       datetime,
	lost_reason       varchar(255),
	fk_user_assign    integer,
	fk_soc            integer,
	fk_socpeople      integer,
	fk_project        integer,
	note_private      text,
	date_anonymized   datetime
) ENGINE=innodb;
