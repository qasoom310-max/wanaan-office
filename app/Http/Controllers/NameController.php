<?php

namespace App\Http\Controllers;

use App\Models\AgentName;
use App\Office\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NameController extends Controller
{
    /**
     * Give an agent its permanent name. Once set it cannot be changed here.
     */
    public function store(Request $request, Team $team): RedirectResponse
    {
        $data = $request->validate([
            'agent' => ['required', 'string'],
            'name' => ['required', 'string', 'min:2', 'max:20', 'regex:/^[\pL ]+$/u'],
        ], ['name.regex' => 'Letters and spaces only (Arabic is fine).']);

        abort_unless($team->exists($data['agent']), 404);
        $name = preg_replace('/\s+/u', ' ', trim($data['name']));

        if (AgentName::where('agent', $data['agent'])->exists()) {
            throw ValidationException::withMessages(['name' => 'This agent already has its permanent name.']);
        }
        if (AgentName::whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => 'Another agent already has this name.']);
        }
        foreach (array_keys($team->all()) as $agent) {
            if (mb_strtolower($team->title($agent)) === mb_strtolower($name)) {
                throw ValidationException::withMessages(['name' => "That's a job title. Pick a person's name."]);
            }
        }

        AgentName::create(['agent' => $data['agent'], 'name' => $name, 'named_by' => $request->user()->id]);

        return back()->with('status', "{$name} is now the {$team->title($data['agent'])}'s permanent name.");
    }
}
