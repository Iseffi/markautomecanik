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

ALTER TABLE llx_markautomecanik_synclog ADD INDEX idx_markautomecanik_synclog_entity (entity);
ALTER TABLE llx_markautomecanik_synclog ADD INDEX idx_markautomecanik_synclog_date_start (date_start);
