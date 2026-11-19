<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Office\AgentModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(AgentModel $model): View
    {
        $key = Setting::read(Setting::ANTHROPIC_API_KEY);

        return view('settings.edit', [
            'connected' => $model->isConfigured(),
            // Only the last four characters are ever shown back.
            'keyEnding' => $key !== null ? substr($key, -4) : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['anthropic_api_key' => ['required', 'string', 'starts_with:sk-ant-', 'max:300']]);

        Setting::write(Setting::ANTHROPIC_API_KEY, trim($data['anthropic_api_key']));

        return back()->with('status', 'AI key saved. The agents can think now.');
    }
}
