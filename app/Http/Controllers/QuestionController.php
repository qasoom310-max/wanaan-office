<?php

namespace App\Http\Controllers;

use App\Models\Fact;
use App\Models\Question;
use App\Office\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class QuestionController extends Controller
{
    public function index(Team $team): View
    {
        return view('questions.index', [
            'open' => Question::whereNull('answered_at')->orderBy('id')->get(),
            'answered' => Question::whereNotNull('answered_at')->latest('answered_at')->limit(50)->get(),
            'team' => $team,
        ]);
    }

    /** An answer becomes a fact for the agent that asked. */
    public function answer(Request $request, Question $question): RedirectResponse
    {
        $data = $request->validate(['answer' => ['required', 'string', 'max:2000']]);

        if (! $question->isOpen()) {
            throw ValidationException::withMessages(['answer' => 'This question was already answered.']);
        }

        DB::transaction(function () use ($question, $data, $request): void {
            $fact = Fact::create([
                'text' => rtrim($question->question, '?').': '.trim($data['answer']),
                'agents' => [$question->agent],
                'confirmed_by' => $request->user()->id,
            ]);

            $question->update([
                'answer' => trim($data['answer']),
                'answered_by' => $request->user()->id,
                'answered_at' => now(),
                'fact_id' => $fact->id,
            ]);
        });

        return back()->with('status', 'Answer saved as a fact.');
    }
}
