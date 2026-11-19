<?php

namespace App\Http\Controllers;

use App\Models\Fact;
use App\Office\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FactController extends Controller
{
    public function index(Team $team): View
    {
        return view('facts.index', ['facts' => Fact::with('confirmer')->latest('id')->get(), 'team' => $team]);
    }

    /** Only the owner confirms facts: a wrong "fact" would mislead every agent. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);

        Fact::create(['text' => trim($data['text']), 'confirmed_by' => $request->user()->id]);

        return back()->with('status', 'Fact saved. Every agent reads it from now on.');
    }
}
