<?php
/* Copyright (C) 2026 Michel L'Hostis <michel.lhostis@iseffi.com>
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
 */

/**
 * \file       markautomecanik/stats.php
 * \ingroup    markautomecanik
 * \brief      Statistics of marketing leads
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)).'/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)).'/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/dolgraph.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once __DIR__.'/class/markautomecaniklead.class.php';

$langs->loadLangs(array('other', 'markautomecanik@markautomecanik'));

if (!isModEnabled('markautomecanik')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('markautomecanik', 'lead', 'read') || !empty($user->socid)) {
	accessforbidden();
}

$year = GETPOSTINT('year');
if ($year < 2000 || $year > 2100) {
	$year = (int) dol_print_date(dol_now(), '%Y');
}
$nbyears = 3;

$lead = new MarkAutoMecanikLead($db);
$statusLabels = MarkAutoMecanikLead::getStatusLabels();

$reports = array();
for ($y = $year - $nbyears + 1; $y <= $year; $y++) {
	$reports[$y] = $lead->getMonthlyStats($y, $user);
}

llxHeader('', $langs->trans('LeadStatistics'), '', '', 0, 0, '', '', '', 'mod-markautomecanik page-stats');

print load_fiche_titre($langs->trans('LeadStatistics'), '', 'fa-chart-bar');

$formother = new FormOther($db);
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="marginbottomonly">';
print $langs->trans('Year').' '.$formother->selectyear($year, 'year', 0, 10, 0, 0, 0, '', 'maxwidth75');
print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Refresh')).'">';
print '</form>';

if (!$user->hasRight('markautomecanik', 'lead', 'readall')) {
	print info_admin($langs->trans('StatsRestrictedToYourLeads'), 0, 0, 'info');
}

// Graph: number of leads per month over the last years (archived and invalid leads excluded)
$data = array();
for ($m = 1; $m <= 12; $m++) {
	$row = array(dol_print_date(dol_mktime(12, 0, 0, $m, 1, $year), '%b'));
	foreach ($reports as $y => $report) {
		$nb = 0;
		if ($report) {
			foreach ($report['month'][$m] as $status => $count) {
				if (!in_array($status, array(MarkAutoMecanikLead::STATUS_INVALID, MarkAutoMecanikLead::STATUS_ARCHIVED), true)) {
					$nb += $count;
				}
			}
		}
		$row[] = $nb;
	}
	$data[] = $row;
}
$graph = new DolGraph();
if (!$graph->isGraphKo()) {
	$dir = $conf->markautomecanik->dir_temp;
	dol_mkdir($dir);
	$graph->SetData($data);
	$graph->SetLegend(array_keys($reports));
	$graph->SetMaxValue($graph->GetCeilMaxValue());
	$graph->SetWidth(DolGraph::getDefaultGraphSizeForStats('width'));
	$graph->SetHeight(DolGraph::getDefaultGraphSizeForStats('height'));
	$graph->SetYLabel($langs->trans('NumberOfLeads'));
	$graph->SetShading(3);
	$graph->SetHorizTickIncrement(1);
	$graph->mode = 'depth';
	$graph->SetTitle($langs->trans('NumberOfLeadsByMonth'));
	$graph->draw($dir.'/leadsbymonth-'.$year.'.png', DOL_URL_ROOT.'/viewimage.php?modulepart=markautomecanik_temp&file=leadsbymonth-'.$year.'.png');
	print '<div class="fichecenter">'.$graph->show().'</div><br>';
}

// Table per month and status for the selected year
$report = $reports[$year];
if ($report) {
	print load_fiche_titre($langs->trans('LeadsByMonthAndStatus', $year), '', '');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Month').'</td>';
	foreach ($statusLabels as $label) {
		print '<td class="right">'.dol_escape_htmltag($label).'</td>';
	}
	print '<td class="right">'.$langs->trans('Total').'</td><td class="right">'.$langs->trans('ConversionRate').'</td></tr>';
	$totals = array_fill_keys(array_keys($statusLabels), 0);
	for ($m = 1; $m <= 12; $m++) {
		$rowTotal = array_sum($report['month'][$m]);
		print '<tr class="oddeven"><td>'.dol_escape_htmltag(dol_print_date(dol_mktime(12, 0, 0, $m, 1, $year), '%B')).'</td>';
		foreach (array_keys($statusLabels) as $status) {
			$nb = $report['month'][$m][$status];
			$totals[$status] += $nb;
			print '<td class="right">'.($nb ? $nb : '<span class="opacitymedium">0</span>').'</td>';
		}
		$valid = $rowTotal - $report['month'][$m][MarkAutoMecanikLead::STATUS_INVALID] - $report['month'][$m][MarkAutoMecanikLead::STATUS_ARCHIVED];
		$rate = $valid > 0 ? round(100 * $report['month'][$m][MarkAutoMecanikLead::STATUS_CONVERTED] / $valid).' %' : '-';
		print '<td class="right"><strong>'.$rowTotal.'</strong></td><td class="right">'.$rate.'</td></tr>';
	}
	$grand = array_sum($totals);
	$valid = $grand - $totals[MarkAutoMecanikLead::STATUS_INVALID] - $totals[MarkAutoMecanikLead::STATUS_ARCHIVED];
	print '<tr class="liste_total"><td>'.$langs->trans('Total').'</td>';
	foreach ($totals as $nb) {
		print '<td class="right">'.$nb.'</td>';
	}
	print '<td class="right">'.$grand.'</td><td class="right">'.($valid > 0 ? round(100 * $totals[MarkAutoMecanikLead::STATUS_CONVERTED] / $valid).' %' : '-').'</td></tr>';
	print '</table></div><br>';

	// Table per sales representative
	print load_fiche_titre($langs->trans('LeadsBySalesRepresentative', $year), '', '');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('SalesRepresentative').'</td>';
	foreach ($statusLabels as $label) {
		print '<td class="right">'.dol_escape_htmltag($label).'</td>';
	}
	print '<td class="right">'.$langs->trans('Total').'</td><td class="right">'.$langs->trans('ConversionRate').'</td></tr>';
	foreach ($report['user'] as $uid => $counts) {
		print '<tr class="oddeven"><td>';
		if ($uid > 0) {
			$u = new User($db);
			print ($u->fetch($uid) > 0 ? $u->getNomUrl(-1) : '#'.((int) $uid));
		} else {
			print '<span class="opacitymedium">'.$langs->trans('NotAssigned').'</span>';
		}
		print '</td>';
		foreach (array_keys($statusLabels) as $status) {
			print '<td class="right">'.((int) $counts[$status]).'</td>';
		}
		$t = array_sum($counts);
		$valid = $t - $counts[MarkAutoMecanikLead::STATUS_INVALID] - $counts[MarkAutoMecanikLead::STATUS_ARCHIVED];
		$listurl = dol_buildpath('/markautomecanik/lead_list.php', 1).'?search_year='.((int) $year).'&search_user_assign='.($uid > 0 ? (int) $uid : -3);
		print '<td class="right"><a href="'.$listurl.'">'.$t.'</a></td>';
		print '<td class="right">'.($valid > 0 ? round(100 * $counts[MarkAutoMecanikLead::STATUS_CONVERTED] / $valid).' %' : '-').'</td></tr>';
	}
	if (empty($report['user'])) {
		print '<tr><td colspan="'.(count($statusLabels) + 3).'"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
	}
	print '</table></div>';
} else {
	dol_print_error($db, $lead->error);
}

llxFooter();
$db->close();
