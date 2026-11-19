<?php

namespace App\Http\Controllers;

use App\Models\MonthlyReport;
use App\Office\Team;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Team $team): View
    {
        return view('reports.index', [
            'months' => MonthlyReport::orderByDesc('month')->orderBy('agent')->get()->groupBy('month'),
            'team' => $team,
        ]);
    }

    public function show(MonthlyReport $report, Team $team): View
    {
        return view('reports.show', ['report' => $report, 'team' => $team]);
    }

    /** The report as a file to keep, share or print. */
    public function download(MonthlyReport $report, Team $team): Response
    {
        $name = str($team->title($report->agent).' '.$report->month)->slug().'.md';

        return response("# {$report->title}\n\n_".$team->label($report->agent).' · '.$report->monthName()."_\n\n".$report->body, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
